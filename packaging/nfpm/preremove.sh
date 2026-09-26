#!/usr/bin/env sh
# Before removal: stop the service. An upgrade leaves it alone (postinstall
# restarts it). Configuration in /etc/qbix, the state in
# /var/lib/exponential-velocity and the qbix user are left for the administrator.
set -e
case "${1:-}" in
  remove|purge|0) ;;   # deb: remove/purge; rpm: 0 = erase
  *) exit 0 ;;         # deb: upgrade/deconfigure/failed-upgrade; rpm: 1 = upgrade
esac
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
  systemctl stop exponential-velocity >/dev/null 2>&1 || true
  systemctl disable exponential-velocity >/dev/null 2>&1 || true
fi
exit 0
