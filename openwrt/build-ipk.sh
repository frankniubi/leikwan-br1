#!/bin/bash
# Convenience wrapper around build.sh that keeps the version stamp in one place.
#
# Usage: ./build-ipk.sh [amd64|arm64] [sdk-dir]
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
VERSION="${LEIKWAND_VERSION:-1.3.0}"
export VERSION
export LEIKWAND_VERSION="$VERSION"
# leikwan-runtime stays at 1.2.12 (the Ultra runtime is unchanged in leikwand 1.3.0).
export LEIKWAN_RUNTIME_VERSION="${LEIKWAN_RUNTIME_VERSION:-1.2.12}"
exec "$HERE/build.sh" "${1:-amd64}" "${2:-}"
