#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
PANEL=root@10.0.10.196
PANEL_ROOT=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
sshpass -e ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'RS'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -l master/src/Support/ProvisioningService.php
php master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php
python3 - <<'PY'
import json, pathlib, hashlib, re
for p in ['master/src/Support/ProvisioningService.php','client-agent/client_agent.py','artifacts/client-agent.py','client-agent/install_forward_runtime.py','artifacts/install-forward-runtime.py','artifacts/versions.json']:
    path=pathlib.Path(p)
    print(p, path.exists(), path.stat().st_size if path.exists() else '', hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else '')
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text())
print('client_agent manifest', versions['client_agent'])
PY
if command -v systemctl >/dev/null 2>&1; then
  systemctl reload php-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.0-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
fi
RS
