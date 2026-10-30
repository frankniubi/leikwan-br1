#!/usr/bin/env bash
set -euo pipefail

# Build the frozen Linux control-plane agents.  PyInstaller is intentionally
# invoked inside the host architecture's Python environment: it does not
# produce a valid arm64 executable when run on amd64 with a cross flag.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCH="${1:-$(uname -m)}"
case "$ARCH" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) echo "unsupported architecture: $ARCH" >&2; exit 2 ;;
esac

PYINSTALLER="${PYINSTALLER:-pyinstaller}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
if [[ "${PYINSTALLER_MODULE:-0}" == "1" ]]; then
  command -v "$PYTHON_BIN" >/dev/null 2>&1 || {
    echo "a Python interpreter with PyInstaller is required" >&2
    exit 2
  }
  PYINSTALLER_CMD=("$PYTHON_BIN" -m PyInstaller)
else
  command -v "$PYINSTALLER" >/dev/null 2>&1 || {
    echo "pyinstaller is required (install PyInstaller 6.x in the build environment)" >&2
    exit 2
  }
  PYINSTALLER_CMD=("$PYINSTALLER")
fi

OUT_DIR="$ROOT/artifacts/python-agent"
WORK_DIR="$ROOT/.build/python-agent"
mkdir -p "$OUT_DIR" "$WORK_DIR"
rm -rf "$WORK_DIR/client" "$WORK_DIR/sidecar"

export PYTHONHASHSEED=0
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-0}"

build_one() {
  local name="$1" entry="$2" source_dir="$3" output="$4"
  local dist="$WORK_DIR/$name/dist" build="$WORK_DIR/$name/build"
  mkdir -p "$dist" "$build"
  "${PYINSTALLER_CMD[@]}" --clean --noconfirm --onefile \
    --name "$name" \
    --paths "$ROOT/$source_dir" \
    --paths "$ROOT/client-agent" \
    --paths "$ROOT/sidecar-agent" \
    --distpath "$dist" \
    --workpath "$build" \
    --specpath "$build" \
    "$ROOT/$entry" >/dev/null
  test -s "$dist/$name"
  file "$dist/$name" | grep -Eq 'ELF 64-bit.*(x86-64|ARM aarch64)' \
    || { echo "${name}: output is not a Linux ELF binary" >&2; exit 1; }
  "$dist/$name" --version >/dev/null
  install -m 0755 "$dist/$name" "$OUT_DIR/$output"
  sha256sum "$OUT_DIR/$output"
}

build_one "leikwan-client-agent" \
  "client-agent/leikwan_client_binary.py" "client-agent" \
  "leikwan-client-agent_linux_${ARCH}"
build_one "leikwan-sidecar-agent" \
  "sidecar-agent/leikwan_sidecar_binary.py" "sidecar-agent" \
  "leikwan-sidecar-agent_linux_${ARCH}"

echo "built frozen agents for linux/${ARCH} in $OUT_DIR"
