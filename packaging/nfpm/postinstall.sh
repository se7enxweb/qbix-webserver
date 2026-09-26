#!/usr/bin/env sh
# After install and upgrade: the service user, the default site enabled, and
# systemd told about the unit. Nothing is started on a fresh install:
# `systemctl enable --now exponential-velocity` does that when you are ready
# (docs/packages.md). An upgrade restarts the service if it was running.
#
# Taking over from the former package name, qbix-webserver, once and only once
# (markers in /var/lib/exponential-velocity): its settings in
# /etc/default/qbix-webserver replace the untouched default, its state in
# /var/lib/qbix-webserver is copied across with its ownership, and a service
# that was enabled or running comes back under the new name.
set -e
state=/var/lib/exponential-velocity
# The service user keeps its name: /etc/qbix, the state and the logs already
# belong to it, so an upgraded system needs no change of ownership.
if ! getent passwd qbix >/dev/null 2>&1; then
  nologin=/usr/sbin/nologin; [ -x "$nologin" ] || nologin=/sbin/nologin
  if command -v useradd >/dev/null 2>&1; then
    useradd --system --home-dir "$state" --no-create-home --shell "$nologin" --user-group qbix
  fi
fi
mkdir -p "$state"
chown qbix:qbix "$state" 2>/dev/null || true
chgrp qbix /etc/qbix/ssl 2>/dev/null || true
if [ ! -e /etc/qbix/sites-enabled/default.conf ] && [ ! -L /etc/qbix/sites-enabled/default.conf ]; then
  ln -s ../sites-available/default.conf /etc/qbix/sites-enabled/default.conf
fi

# Settings: the old file, or what rpm saved of it when the old package went.
old=/etc/default/qbix-webserver
[ -f "$old" ] || old=/etc/default/qbix-webserver.rpmsave
new=/etc/default/exponential-velocity
if [ -f "$old" ] && [ ! -e "$state/.settings-migrated" ]; then
  [ -f "$new" ] && cp -p "$new" "$new.packaged"
  sed 's#/usr/share/qbix-webserver/#/usr/share/exponential-velocity/#g' "$old" > "$new.migrating"
  chmod 0644 "$new.migrating" && mv "$new.migrating" "$new"
  : > "$state/.settings-migrated"
  echo "exponential-velocity: settings carried over from $old (the packaged default is in $new.packaged)"
fi
# State: copied, never overwriting what is already here, ownership kept.
if [ -d /var/lib/qbix-webserver ] && [ ! -L /var/lib/qbix-webserver ] && [ ! -e "$state/.state-migrated" ]; then
  cp -a -n /var/lib/qbix-webserver/. "$state/" 2>/dev/null || true
  : > "$state/.state-migrated"
  echo "exponential-velocity: state copied from /var/lib/qbix-webserver (the old directory is left for you to remove)"
fi

# The rest waits for rpm's posttrans while the old package is still installed
# (rpm removes an obsoleted package after this script).
if command -v rpm >/dev/null 2>&1 && rpm -q qbix-webserver >/dev/null 2>&1; then
  exit 0
fi
# The former install path still resolves, for scripts and settings that name it.
# rpm leaves the old package's directories behind, empty: those go, anything
# holding a file stays.
if [ -d /usr/share/qbix-webserver ] && [ ! -L /usr/share/qbix-webserver ] \
   && [ -z "$(find /usr/share/qbix-webserver ! -type d -print 2>/dev/null | head -n 1)" ]; then
  find /usr/share/qbix-webserver -depth -type d -empty -delete 2>/dev/null || true
fi
if [ ! -e /usr/share/qbix-webserver ] && [ ! -L /usr/share/qbix-webserver ]; then
  ln -s exponential-velocity /usr/share/qbix-webserver
fi
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload >/dev/null 2>&1 || true
  if [ -f "$state/.migrate-service" ]; then
    grep -qx enabled "$state/.migrate-service" && systemctl enable exponential-velocity >/dev/null 2>&1 || true
    grep -qx active "$state/.migrate-service" && systemctl start exponential-velocity >/dev/null 2>&1 || true
    systemctl disable qbix-webserver >/dev/null 2>&1 || true
    rm -f "$state/.migrate-service"
    echo "exponential-velocity: the qbix-webserver service now runs as exponential-velocity"
  else
    systemctl try-restart exponential-velocity >/dev/null 2>&1 || true
  fi
fi
if [ -e "$state/.state-migrated" ] && ! systemctl is-enabled --quiet exponential-velocity 2>/dev/null; then
  echo "exponential-velocity: if qbix-webserver was running, start it again with  systemctl enable --now exponential-velocity"
fi
echo "exponential-velocity: start it with  systemctl enable --now exponential-velocity"
echo "exponential-velocity: check PHP's extensions with  qbixctl ext:check"
exit 0
