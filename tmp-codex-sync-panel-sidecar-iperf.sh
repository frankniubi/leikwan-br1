#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
PANEL=root@10.0.10.196
REMOTE=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
files=(
  master/src/Controllers/SidecarAgentController.php
  master/src/Support/ProvisioningService.php
  sidecar-agent/sidecar_agent.py
  artifacts/versions.json
)
for f in "${files[@]}"; do
  dir=$(dirname "$f")
  sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" "$PANEL" "mkdir -p '$REMOTE/$dir'"
  sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" "$f" "$PANEL:$REMOTE/$f" >/dev/null
 done
cat >/tmp/codex_verify_panel_sync.sh <<'RS'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Support/ProvisioningService.php
python3 -m py_compile sidecar-agent/sidecar_agent.py
sha256sum master/src/Controllers/SidecarAgentController.php master/src/Support/ProvisioningService.php sidecar-agent/sidecar_agent.py artifacts/versions.json
python3 - <<'PY'
import json, pathlib, re, hashlib
v=json.loads(pathlib.Path('artifacts/versions.json').read_text())['sidecar_agent']
p=pathlib.Path(v['path'])
text=p.read_text()
print('sidecar_agent_version_meta', v['version'])
print('sidecar_agent_declared', re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', text).group(1))
print('sidecar_agent_sha_meta', v.get('sha256'))
print('sidecar_agent_sha_actual', hashlib.sha256(p.read_bytes()).hexdigest())
PY
RS
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_verify_panel_sync.sh "$PANEL:/tmp/codex_verify_panel_sync.sh" >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash /tmp/codex_verify_panel_sync.sh'
