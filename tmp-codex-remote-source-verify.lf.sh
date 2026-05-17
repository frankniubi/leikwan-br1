#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
for f in \
  client-agent/client_agent.py \
  client-agent/install_forward_runtime.py \
  client-agent/leikwan_mptcp_client.py \
  sidecar-agent/sidecar_agent.py \
  sidecar-agent/leikwan_mptcp_sidecar.py \
  master/src/Support/ProvisioningService.php \
  master/src/Controllers/ClientAgentController.php \
  master/src/Controllers/SidecarAgentController.php \
  master/src/Controllers/ArtifactController.php \
  install/install-client-agent.sh \
  artifacts/versions.json; do
  [ -f "$f" ] || { echo "MISSING $f"; exit 2; }
  echo "--- $f"; sha256sum "$f"; stat -c '%s %y' "$f"
done
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Controllers/ArtifactController.php
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py sidecar-agent/sidecar_agent.py sidecar-agent/leikwan_mptcp_sidecar.py
REMOTE