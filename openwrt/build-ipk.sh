#!/bin/bash
# Convenience wrapper around build.sh that keeps the version stamp in one place.
#
# Usage: ./build-ipk.sh [amd64|arm64] [sdk-dir]
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
VERSION="${LEIKWAND_VERSION:-1.2.9}"
export VERSION
export LEIKWAND_VERSION="$VERSION"
# The runtime is unchanged since 1.2.8; keep its package version (and attested binary) as is.
export LEIKWAN_RUNTIME_VERSION="${LEIKWAN_RUNTIME_VERSION:-1.2.8}"
exec "$HERE/build.sh" "${1:-amd64}" "${2:-}"
