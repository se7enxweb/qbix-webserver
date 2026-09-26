#!/usr/bin/env bash
# Installs a package in a clean container of its distribution and checks it:
# dependencies resolve, `qbixctl ext:check --variant=lite` finds everything
# the platform requires, and the server serves a page as the service user.
#
# Given the package under its former name (qbix-webserver) as well, it first
# installs that one, with changed settings, some state and its service
# enabled and running (a stand-in systemctl records what the scripts ask of
# it), then installs the new package over it and checks the takeover: the old
# package is gone, its name is provided, settings and state came across, the
# service moved to the new name, and the old install path still resolves.
#
#   bash packaging/ci/test-package.sh <distro> <package file> [<old package file>]
set -euo pipefail
cd "$(dirname "$0")/../.."
distro="${1:?distro}"; pkg="${2:?package file}"; old="${3:-}"
case "$distro" in
  debian-12) image=debian:12 ;;           debian-13) image=debian:13 ;;
  ubuntu-22.04) image=ubuntu:22.04 ;;     ubuntu-24.04) image=ubuntu:24.04 ;;
  el-9) image=almalinux:9 ;;              el-10) image=almalinux:10 ;;
  *) echo "unknown distribution $distro" >&2; exit 2 ;;
esac
file="$(basename "$pkg")"
oldmount=(); oldfile=''
if [ -n "$old" ]; then oldmount=(-v "$PWD/$(dirname "$old"):/old:ro"); oldfile="$(basename "$old")"; fi
docker run --rm -v "$PWD/$(dirname "$pkg"):/pkgs:ro" "${oldmount[@]}" "$image" sh -ec "
  install_pkg() {
    case '$distro' in
      debian-*|ubuntu-*)
        export DEBIAN_FRONTEND=noninteractive
        for try in 1 2 3; do
          apt-get update -qq && apt-get install -y -qq curl \"\$1\" >/tmp/apt.log 2>&1 && return 0
          echo \"apt failed, retry \$try of 3\"; tail -5 /tmp/apt.log; sleep 10
        done; return 1 ;;
      el-*)
        # Mirrors fail now and then ('Cannot download, all mirrors'): retried.
        # util-linux for runuser: the minimal EL 10 image has neither su nor runuser.
        for try in 1 2 3; do
          dnf -y -q install util-linux \"\$1\" && return 0
          echo \"dnf failed, retry \$try of 3\"; dnf clean all >/dev/null; sleep 10
        done; return 1 ;;
    esac
  }
  installed() { case '$distro' in debian-*|ubuntu-*) dpkg-query -W -f='\${Status}' \"\$1\" 2>/dev/null | grep -q 'install ok installed' ;; el-*) rpm -q \"\$1\" >/dev/null 2>&1 ;; esac; }
  [ '$distro' = el-9 ] && dnf -y -q module enable php:8.2

  if [ -n '$oldfile' ]; then
    echo '== the former package, qbix-webserver'
    install_pkg /old/'$oldfile'
    installed qbix-webserver
    echo 'QBIX_OPTS=--workers=3' >> /etc/default/qbix-webserver
    mkdir -p /var/lib/qbix-webserver && echo kept > /var/lib/qbix-webserver/state-file
    chown -R qbix:qbix /var/lib/qbix-webserver
    # A stand-in systemctl: qbix-webserver enabled and running, every call logged.
    mkdir -p /run/systemd/system
    printf '%s\n' '#!/bin/sh' 'echo \"\$*\" >> /tmp/systemctl.log' \
      'case \"\$*\" in *is-enabled*qbix-webserver*|*is-active*qbix-webserver*) exit 0 ;; *is-enabled*|*is-active*) exit 1 ;; esac' \
      'exit 0' > /usr/bin/systemctl
    chmod 0755 /usr/bin/systemctl
    echo '== upgrading to the new package'
    install_pkg /pkgs/'$file'
    installed exponential-velocity
    ! installed qbix-webserver || { echo 'the old package is still installed'; exit 1; }
    case '$distro' in
      debian-*|ubuntu-*) dpkg-query -W -f='\${Provides}' exponential-velocity | grep -q qbix-webserver ;;
      el-*) rpm -q --whatprovides qbix-webserver | grep -q exponential-velocity ;;
    esac
    echo 'provides qbix-webserver: yes'
    grep -q 'workers=3' /etc/default/exponential-velocity || { echo 'settings not carried over'; cat /etc/default/exponential-velocity; exit 1; }
    echo 'settings carried over: yes'
    [ \"\$(cat /var/lib/exponential-velocity/state-file)\" = kept ] || { echo 'state not copied'; exit 1; }
    [ \"\$(stat -c %U /var/lib/exponential-velocity/state-file)\" = qbix ] || { echo 'state ownership lost'; exit 1; }
    echo 'state copied, owned by qbix: yes'
    grep -q 'enable exponential-velocity' /tmp/systemctl.log && grep -q 'start exponential-velocity' /tmp/systemctl.log \
      || { echo 'service not moved:'; cat /tmp/systemctl.log; exit 1; }
    grep -q 'stop qbix-webserver' /tmp/systemctl.log || { echo 'old service not stopped:'; cat /tmp/systemctl.log; exit 1; }
    echo 'service moved to exponential-velocity: yes'
    [ -L /usr/share/qbix-webserver ] && [ -f /usr/share/qbix-webserver/bin/qbixserver.phar ] || { echo 'the old install path does not resolve'; exit 1; }
    echo 'old install path resolves: yes'
    echo '== takeover PASS'
  else
    install_pkg /pkgs/'$file'
    installed exponential-velocity
  fi
  echo '== installed'
  qbixctl ext:check --variant=lite
  echo '== serving as the service user'
  cd /var/lib/exponential-velocity
  # runuser where there is one (util-linux), else su.
  serve='qbixserver --root=/usr/share/exponential-velocity/web --port=18080 >/tmp/qbix.log 2>&1 &'
  if command -v runuser >/dev/null 2>&1; then runuser -u qbix -- sh -c \"\$serve\"
  else su -s /bin/sh qbix -c \"\$serve\"; fi
  for i in 1 2 3 4 5 6 7 8 9 10; do
    code=\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/ || true)
    [ \"\$code\" = 200 ] && break; sleep 1
  done
  echo \"page: \$code\"; [ \"\$code\" = 200 ] || { cat /tmp/qbix.log; exit 1; }
  curl -s http://127.0.0.1:18080/Q/health | head -c 80; echo
  echo PASS
" 2>&1 | sed "s/^/[$distro] /"
exit "${PIPESTATUS[0]}"
