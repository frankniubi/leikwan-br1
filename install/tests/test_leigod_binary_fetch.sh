#!/usr/bin/env bash
# A5: assert the installers self-host the LeiGod basis binaries (gost + leigod accelerator),
# gate the slave-side fetch with check_binary_current (sha256-skip), and write the gost_bin /
# leigod_bin config keys. Pure static assertions over the install scripts + versions.json.
set -euo pipefail
cd "$(dirname "$0")/../.."

fail=0
need() { if ! grep -q "$1" "$2"; then echo "FAIL: '$1' not found in $2"; fail=1; fi; }

# slave installer: fetch both binaries, sha-skip, and config keys
need 'artifacts/gost/gost_linux_amd64' install/install-slave.sh
need 'artifacts/leigod/leigod-linux-amd64' install/install-slave.sh
need '_vers_sha256 gost_linux_amd64' install/install-slave.sh
need 'check_binary_current "$BIN_DIR/gost"' install/install-slave.sh
need '"gost_bin"' install/install-slave.sh
need '"leigod_bin"' install/install-slave.sh

# sidecar installer: gost server binary
need 'artifacts/gost/gost_linux_amd64' install/install-bond-sidecar.sh

# versions.json: entries present + valid JSON
need '"gost_linux_amd64"' artifacts/versions.json
need '"leigod_linux_amd64"' artifacts/versions.json
python -c "import json; json.load(open('artifacts/versions.json')); print('versions.json valid JSON')"

if [[ "$fail" -ne 0 ]]; then echo "test_leigod_binary_fetch FAILED"; exit 1; fi
echo "ok"
