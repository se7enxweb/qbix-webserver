#!/usr/bin/env sh
# rpm only, after the whole transaction: the obsoleted qbix-webserver package
# is gone by now, so the former install path can become a link and a service
# that was enabled or running under that name comes back under the new one.
state=/var/lib/exponential-velocity
# rpm leaves the old package's directories behind, empty: those go, anything
# holding a file stays.
if [ -d /usr/share/qbix-webserver ] && [ ! -L /usr/share/qbix-webserver ] \
   && [ -z "$(find /usr/share/qbix-webserver ! -type d -print 2>/dev/null | head -n 1)" ]; then
  find /usr/share/qbix-webserver -depth -type d -empty -delete 2>/dev/null || true
fi
if [ ! -e /usr/share/qbix-webserver ] && [ ! -L /usr/share/qbix-webserver ]; then
  ln -s exponential-velocity /usr/share/qbix-webserver
fi
if [ -f "$state/.migrate-service" ] && [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload >/dev/null 2>&1 || true
  grep -qx enabled "$state/.migrate-service" && systemctl enable exponential-velocity >/dev/null 2>&1 || true
  grep -qx active "$state/.migrate-service" && systemctl start exponential-velocity >/dev/null 2>&1 || true
  systemctl disable qbix-webserver >/dev/null 2>&1 || true
  rm -f "$state/.migrate-service"
  echo "exponential-velocity: the qbix-webserver service now runs as exponential-velocity"
fi
exit 0
