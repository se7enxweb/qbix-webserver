#!/usr/bin/env sh
# Does the phar serve a page on this platform?
#
# The static binaries exist for five targets, because that is what the build
# toolchain ships for. The phar has no such limit: it is PHP, so it runs
# wherever PHP 8.1 or later does -- the BSDs, the illumos family, the Linux
# architectures nobody builds binaries for, and Haiku. This is the test that
# turns that from a claim into something we have watched happen.
#
# Deliberately POSIX sh with no bashisms, no arrays, no `local`, no pipefail,
# and no tool that is not on a minimal install. It has to run under Haiku's
# sh and Solaris' /bin/sh as well as bash, so the moment it needs bash it
# stops being able to answer the question it exists to ask.
#
#   sh tests/phar-serves.sh [path/to/qbixserver.phar]
#
# PHP=/path/to/php  overrides the interpreter.

set -u

PHAR="${1:-bin/qbixserver.phar}"
PHP="${PHP:-php}"
PORT="${PORT:-19777}"

# Several systems install a versioned interpreter and no plain "php": OpenBSD
# gives php-8.3, pkgsrc gives php83. Without this the script reported "no php"
# on a platform that plainly has one, which reads as the platform being unable
# to run the server rather than as this script not having looked properly.
if ! command -v "$PHP" >/dev/null 2>&1; then
    for _candidate in php8.4 php8.3 php8.2 php8.1 \
                      php-8.4 php-8.3 php-8.2 php-8.1 \
                      php84 php83 php82 php81; do
        if command -v "$_candidate" >/dev/null 2>&1; then
            PHP="$_candidate"
            break
        fi
    done
fi

echo "=============================================="
echo " Exponential Velocity - does the phar serve?"
echo "=============================================="
echo

if [ ! -f "$PHAR" ]; then
    echo "  FAIL  no phar at $PHAR"
    exit 1
fi

if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "  FAIL  no php: set PHP=/path/to/php"
    exit 1
fi

echo "  platform : $(uname -s) $(uname -m)"
echo "  php      : $("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
echo "  phar     : $PHAR"
echo

# Phar reading can be switched off by the ini, and the failure then looks like
# a broken archive rather than a policy. Say which it is.
if [ "$("$PHP" -r 'echo ini_get("phar.readonly") === false ? "missing" : "ok";' 2>/dev/null)" = "missing" ]; then
    echo "  FAIL  this php has no phar support"
    exit 1
fi

TMP="${TMPDIR:-/tmp}/qbix-phar-serves.$$"
mkdir -p "$TMP/web" || { echo "  FAIL  cannot create $TMP"; exit 1; }
printf '<?php echo "served by the phar";' > "$TMP/web/index.php"

# Fetch with whatever the platform has. curl is not everywhere; FreeBSD has
# fetch in the base system and several others only have wget.
fetch_body() {
    if command -v curl >/dev/null 2>&1; then
        curl -s --max-time 10 "$1" 2>/dev/null
    elif command -v fetch >/dev/null 2>&1; then
        fetch -q -o - "$1" 2>/dev/null
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O - "$1" 2>/dev/null
    else
        # Last resort: PHP is present by definition, so use it.
        "$PHP" -r 'echo @file_get_contents($argv[1]);' "$1" 2>/dev/null
    fi
}

# On a failure, say how the fetch itself went: which client, its exit status,
# how many bytes came back, and (with curl) the connection headers -- so a
# platform that "serves nothing" (illumos/omnios does this today) can be told
# apart, connection refused versus connected but an empty body, without a
# shell on the box. Runs only on the failure path, so the pass output on every
# other platform is unchanged.
fetch_diag() {
    _p="$1"
    for _h in 127.0.0.1 localhost; do
        _u="http://$_h:$_p/"
        if command -v curl >/dev/null 2>&1; then
            _w="$(curl -s --max-time 10 -o /dev/null -w 'http=%{http_code} bytes=%{size_download} time=%{time_total}s' "$_u" 2>/dev/null)"
            echo "        curl $_u -> exit $?, ${_w:-no output}"
            curl -sv --max-time 10 -o /dev/null "$_u" 2>&1 | grep -E '^[*<>]' | sed 's/^/          /' | head -12
        elif command -v fetch >/dev/null 2>&1; then
            fetch -q -o - "$_u" >/dev/null 2>&1
            echo "        fetch $_u -> exit $?"
        elif command -v wget >/dev/null 2>&1; then
            wget -q -O - "$_u" >/dev/null 2>&1
            echo "        wget $_u -> exit $?"
        fi
    done
}

"$PHP" "$PHAR" --root="$TMP/web" --port="$PORT" --workers=2 > "$TMP/log" 2>&1 &
SERVER=$!

cleanup() {
    kill "$SERVER" 2>/dev/null
    # Give it a moment to go before taking the directory out from under it.
    i=0
    while [ $i -lt 20 ]; do
        kill -0 "$SERVER" 2>/dev/null || break
        i=$((i + 1))
        sleep 1
    done
    kill -9 "$SERVER" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

# Startup includes a pre-warm walk, so poll rather than guess at a sleep. The
# limit is generous because several of the platforms this runs on are emulated
# a whole architecture at a time, where everything takes the time it takes and
# a short deadline measures the emulator rather than the server.
WAIT="${WAIT:-120}"
BODY=""
i=0
while [ $i -lt "$WAIT" ]; do
    BODY="$(fetch_body "http://127.0.0.1:$PORT/")"
    [ -n "$BODY" ] && break
    if ! kill -0 "$SERVER" 2>/dev/null; then
        # Reap it so $? is the exit status rather than "no such job". A server
        # that died without writing a line is the case that most needs the
        # status reported: otherwise there is nothing at all to go on.
        wait "$SERVER" 2>/dev/null
        STATUS=$?
        echo "  FAIL  the server exited during startup (status $STATUS)"
        echo
        if [ -s "$TMP/log" ]; then
            sed 's/^/    /' "$TMP/log" 2>/dev/null | tail -20
        else
            echo "    it wrote nothing before exiting"
            echo "    php: $("$PHP" -r 'echo PHP_VERSION." ".PHP_OS_FAMILY;' 2>&1)"
            echo "    extensions the server needs:"
            for x in pcntl posix sockets tokenizer phar session; do
                printf '      %-10s %s\n' "$x" \
                    "$("$PHP" -r "echo extension_loaded('$x') ? 'yes' : 'MISSING';" 2>&1)"
            done
        fi
        exit 1
    fi
    i=$((i + 1))
    sleep 1
done

FAILED=0

if [ "$BODY" = "served by the phar" ]; then
    echo "  ok    the phar serves a page"
else
    echo "  FAIL  the phar did not serve the page"
    echo "        got:  '$BODY'"
    echo "        want: 'served by the phar'"
    fetch_diag "$PORT"
    FAILED=1
fi

# A second request goes to a worker that has already served one, which is the
# case persistent workers get wrong when they get anything wrong.
BODY2="$(fetch_body "http://127.0.0.1:$PORT/")"
if [ "$BODY2" = "served by the phar" ]; then
    echo "  ok    a reused worker serves the same page"
else
    echo "  FAIL  the second request differed from the first"
    echo "        got:  '$BODY2'"
    FAILED=1
fi

# The baseline, on this PHP. The phar runs on whatever PHP the host has, so
# it cannot provide extensions -- only report them. What the server itself
# needs (the mini variant, or CHECK_VARIANT) must all be there; lite and
# standard are shown, not required, with the host's own install command.
CTL="$(dirname "$PHAR")/../qbixctl.php"
if [ -f "$CTL" ]; then
    CHECK_VARIANT="${CHECK_VARIANT:-mini}"
    if "$PHP" "$CTL" ext:check --variant="$CHECK_VARIANT" >"$TMP/check" 2>&1; then
        echo "  ok    this PHP has every extension the $CHECK_VARIANT variant lists"
    else
        echo "  FAIL  this PHP lacks extensions the $CHECK_VARIANT variant needs:"
        sed -n '/^missing:/,$p' "$TMP/check" | sed 's/^/        /'
        FAILED=1
    fi
    for v in lite standard; do
        "$PHP" "$CTL" ext:check --variant="$v" >"$TMP/check" 2>&1 \
            && echo "  info  $v: complete" \
            || echo "  info  $v: $(grep '^missing:' "$TMP/check" | cut -c1-160)"
    done
fi

echo
if [ "$FAILED" -eq 0 ]; then
    echo "  PASS - $(uname -s) $(uname -m) can run Exponential Velocity"
    exit 0
fi
echo "  FAIL - $(uname -s) $(uname -m)"
echo
echo "  server log:"
sed 's/^/    /' "$TMP/log" 2>/dev/null | tail -30
exit 1
