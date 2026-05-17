#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set +e
echo ===toolchain===
ldd --version | head -1
command -v cargo; cargo --version
command -v rustc; rustc --version
command -v gcc; gcc --version | head -1
command -v curl; command -v tar; command -v make
cat /etc/os-release | head -8
'