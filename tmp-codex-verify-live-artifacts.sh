#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  sha256sum /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-client-agent.py;
  python3 - <<"PY"
import hashlib, pathlib
for p in [pathlib.Path('/usr/local/bin/install_forward_runtime.py'), pathlib.Path('/usr/local/bin/leikwan-client-agent.py')]:
    print(p, p.stat().st_size)
PY
'