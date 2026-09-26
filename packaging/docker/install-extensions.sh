#!/usr/bin/env bash
# Installs a variant's PHP extensions into an official php:<ver>-cli image,
# from the baseline (build/extensions.json via plan.php), in one layer:
# build packages in, extensions compiled, runtime libraries kept, build
# packages out.
#
#   install-extensions.sh <variant> <with-addons 0|1>
#
# The server, required and recommended tiers must all install: a failure
# there fails the build. An extension of the extra tier (the full image) that
# does not build on this PHP is left out and recorded, with the reason, in
# /usr/share/exponential-velocity/image-extensions.txt -- the image's own list of
# its exceptions, which `docker run ... cat` shows.
set -euo pipefail
variant="${1:?variant}"
addons="${2:-1}"
here="$(cd "$(dirname "$0")" && pwd)"
report=/usr/share/exponential-velocity/image-extensions.txt
jobs="$(nproc)"
export DEBIAN_FRONTEND=noninteractive

log() { printf '\n== %s\n' "$*"; }
note() { printf '%s\n' "$*" >> "$report"; }

: > "$report"
note "# PHP extensions in this image: variant $variant, PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || php -v | head -1)"

saved_manual="$(apt-mark showmanual)"
apt-get update -qq
# phpize, a compiler and pecl's needs; the image names them in PHPIZE_DEPS.
apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS unzip >/dev/null

docker-php-source extract
plan="$(php "$here/plan.php" "$variant" "$addons")"
printf '%s\n' "$plan" | sed 's/^/  plan: /'

deps=$(printf '%s\n' "$plan" | awk '$1=="DEP"{print $2}' | tr '\n' ' ')
if [ -n "$deps" ]; then
  log "build packages: $deps"
  # All at once, then one by one if that fails, so one package the release
  # lacks costs only the extensions that need it -- and says which.
  if ! apt-get install -y -qq --no-install-recommends $deps >/dev/null; then
    for p in $deps; do
      apt-get install -y -qq --no-install-recommends "$p" >/dev/null || echo "  warning: no package $p"
    done
  fi
fi

fail_hard=0
install_one() { # name tier how [configure...]
  local name="$1" tier="$2" how="$3"; shift 3
  local ok=0
  if [ "$how" = src ]; then
    if [ "$name" = odbc ]; then
      # ext/odbc's configure refuses a plain --with-unixODBC on the official
      # images; the widely used fix is to configure it by hand.
      ( cd /usr/src/php/ext/odbc && phpize >/dev/null \
          && sed -ri 's@^ *test +"\$PHP_.*" *= *"no" *&& *PHP_.*=yes *$@#&@g' configure \
          && ./configure --with-unixODBC=shared,/usr >/dev/null ) \
        && docker-php-ext-install -j"$jobs" odbc >/dev/null && ok=1
    else
      if [ "$#" -gt 0 ]; then docker-php-ext-configure "$name" "$@" >/dev/null || true; fi
      docker-php-ext-install -j"$jobs" "$name" >/dev/null && ok=1
    fi
  else
    # PECL asks questions for some packages; an empty answer takes the
    # default. A finite set of answers, not `yes`: under pipefail, yes dies of
    # SIGPIPE once pecl exits and the pipeline reported every install failed.
    if printf '\n\n\n\n\n\n\n\n\n\n' | pecl install -o -f "$name" > "/tmp/pecl-$name.log" 2>&1; then
      docker-php-ext-enable "$name" >/dev/null && ok=1
    fi
  fi
  if [ "$ok" = 1 ]; then
    note "installed  $name ($tier, $how)"
    return 0
  fi
  [ -s "/tmp/pecl-$name.log" ] && [ "$how" = pecl ] && tail -5 "/tmp/pecl-$name.log" | sed "s/^/    pecl: /"
  case "$tier" in
    server|required|recommended)
      echo "::error::$name ($tier) did not install on PHP $(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
      note "FAILED     $name ($tier, $how)"
      fail_hard=1 ;;
    *)
      echo "  note: $name ($tier) does not build here; left out"
      note "left out   $name ($tier): does not build with $how on this PHP" ;;
  esac
}

log "extensions"
while read -r kind name tier how rest; do
  [ "$kind" = EXT ] || continue
  echo "  $name ($tier, $how)"
  # shellcheck disable=SC2086
  install_one "$name" "$tier" "$how" $rest
done <<< "$plan"
printf '%s\n' "$plan" | awk '$1=="HAVE"{print "built in   " $2}' >> "$report"
printf '%s\n' "$plan" | awk '$1=="SKIP"{$1=""; sub(/^ /,""); n=$1; $1=""; print "not here   " n ":" $0}' >> "$report"

runtime_extra=""
if [ "$addons" = 1 ]; then
  log "add-on drivers"
  while read -r kind name how; do
    [ "$kind" = ADDON ] || continue
    case "$how" in
      oracle-src|oracle-pecl)
        if [ ! -d /opt/oracle/instantclient ]; then
          arch=$(uname -m); suffix=linuxx64; [ "$arch" = aarch64 ] && suffix=linux-arm64
          mkdir -p /opt/oracle && cd /opt/oracle
          for part in basic sdk; do
            curl -fsSL -o "ic-$part.zip" "https://download.oracle.com/otn_software/linux/instantclient/instantclient-$part-$suffix.zip"
            unzip -qo "ic-$part.zip" && rm -f "ic-$part.zip"
          done
          ln -sfn "$(ls -d /opt/oracle/instantclient_* | sort | tail -1)" /opt/oracle/instantclient
          echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf && ldconfig
          apt-get install -y -qq --no-install-recommends libaio1 >/dev/null || apt-get install -y -qq libaio1t64 >/dev/null || true
          cd - >/dev/null
        fi
        runtime_extra="$runtime_extra libaio1"
        if [ "$how" = oracle-src ]; then
          install_one "$name" addon src "--with-${name//_/-}=instantclient,/opt/oracle/instantclient"
        elif printf 'instantclient,/opt/oracle/instantclient\n' | pecl install -o -f "$name" >/dev/null 2>&1 \
             && docker-php-ext-enable "$name" >/dev/null; then
          note "installed  $name (add-on, pecl, Oracle Instant Client)"
        else
          note "left out   $name (add-on): did not build against Oracle Instant Client on this PHP"
        fi ;;
      src) install_one "$name" addon src ;;
      *,*|*-*|*)
        if [ "$name" = odbc-drivers ]; then runtime_extra="$runtime_extra ${how//,/ }"; fi ;;
    esac
  done <<< "$plan"
fi

# opcache for the CLI server: this image runs PHP as a long-lived CLI server,
# where opcache is off unless enable_cli says otherwise.
if php -m | grep -qi '^Zend OPcache$'; then
  cat > "$PHP_INI_DIR/conf.d/zz-qbix-opcache.ini" <<'INI'
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=64M
INI
fi

log "keeping runtime libraries, removing build packages"
apt-mark auto '.*' >/dev/null
[ -z "$saved_manual" ] || apt-mark manual $saved_manual >/dev/null
find /usr/local -type f \( -name '*.so' -o -name '*.so.*' \) -exec ldd '{}' ';' 2>/dev/null \
  | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1 || index(so, "/opt/") == 1) next; gsub("^/(usr/)?", "", so); print so }' \
  | sort -u | xargs -r dpkg-query --search 2>/dev/null | cut -d: -f1 | sort -u | xargs -r apt-mark manual >/dev/null
apt-get purge -y -qq --auto-remove -o APT::AutoRemove::RecommendsImportant=false >/dev/null
if [ -n "$runtime_extra" ]; then
  # shellcheck disable=SC2086
  apt-get install -y -qq --no-install-recommends $runtime_extra >/dev/null || echo "  warning: some runtime packages missing: $runtime_extra"
fi
docker-php-source delete
rm -rf /var/lib/apt/lists/* /tmp/pear /tmp/pecl-*.log ~/.pearrc

log "result"
cat "$report"
[ "$fail_hard" = 0 ] || { echo "::error::a required extension did not install"; exit 1; }
