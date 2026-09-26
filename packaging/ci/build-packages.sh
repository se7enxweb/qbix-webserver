#!/usr/bin/env bash
# Builds the deb and rpm packages, one per distribution, with nfpm.
#
#   bash packaging/ci/build-packages.sh <version> [distro ...]   -> dist/
#
# NFPM is the nfpm command (default: nfpm on PATH, else the goreleaser/nfpm
# container). Specs come from packaging/nfpm/render.php, so a package's
# dependencies are the baseline's in that distribution's names.
set -euo pipefail
cd "$(dirname "$0")/../.."
version="${1:?version}"; shift || true
version="${version#refs/tags/}"; version="${version#v}"
mkdir -p dist
if [ "$#" -eq 0 ]; then set -- $(php packaging/nfpm/render.php --list | awk '{print $1}'); fi

nfpm_run() {
  if [ -n "${NFPM:-}" ]; then $NFPM "$@"
  elif command -v nfpm >/dev/null 2>&1; then nfpm "$@"
  else docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/tmp/pkg" -w /tmp/pkg goreleaser/nfpm:v2.41.1 "$@"
  fi
}

for distro in "$@"; do
  read -r _ format suffix <<< "$(php packaging/nfpm/render.php --list | awk -v d="$distro" '$1==d')"
  [ -n "$format" ] || { echo "unknown distribution $distro" >&2; exit 2; }
  spec="dist/nfpm-$distro.yaml"
  php packaging/nfpm/render.php "$distro" "$version" > "$spec"
  if [ "$format" = deb ]; then
    out="dist/exponential-velocity_${version}-1+${suffix}_all.deb"
  else
    out="dist/exponential-velocity-${version}-1.${suffix}.noarch.rpm"
  fi
  nfpm_run pkg --config "$spec" --packager "$format" --target "$out"
  rm -f "$spec"
  ls -l "$out"
done
