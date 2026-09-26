#!/usr/bin/env sh
# Before install: taking over from the package's former name, qbix-webserver.
# If its service is enabled or running, note that for postinstall (or, on rpm,
# posttrans) to carry over, and stop it now so the new service can bind its
# ports. Runs before the old package is removed on rpm (Obsoletes) and, when
# apt unpacks before removing, on deb too; otherwise postinstall says what to do.
set -e
state=/var/lib/exponential-velocity
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
  was=''
  systemctl is-enabled --quiet qbix-webserver 2>/dev/null && was="${was}enabled
"
  systemctl is-active --quiet qbix-webserver 2>/dev/null && was="${was}active
"
  if [ -n "$was" ]; then
    mkdir -p "$state"
    printf '%s' "$was" > "$state/.migrate-service"
    systemctl stop qbix-webserver >/dev/null 2>&1 || true
  fi
fi
exit 0
