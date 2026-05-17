#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
mkdir -p master/public/artifacts master/public/artifacts/wstunnel
# Keep public artifact mirror aligned with repo/controller artifacts used by ARTIFACT_BASE_URL.
install -m 0755 client-agent/client_agent.py master/public/artifacts/client-agent.py
install -m 0755 client-agent/install_forward_runtime.py master/public/artifacts/install-forward-runtime.py
install -m 0755 client-agent/leikwan_mptcp_client.py master/public/artifacts/leikwan-mptcp-client.py
install -m 0755 sidecar-agent/sidecar_agent.py master/public/artifacts/sidecar-agent.py
install -m 0755 sidecar-agent/leikwan_mptcp_sidecar.py master/public/artifacts/leikwan-mptcp-sidecar.py
install -m 0755 sidecar-agent/leikwan_udp_uot_merge.py master/public/artifacts/leikwan-udp-uot-merge.py 2>/dev/null || true
install -m 0755 install/install-bond-sidecar.sh master/public/artifacts/install-bond-sidecar.sh
install -m 0755 install/sidecar-agent-update.sh master/public/artifacts/sidecar-agent-update.sh
install -m 0755 install/install-client-agent.sh master/public/artifacts/install-client-agent.sh
install -m 0755 install/client-agent-update.sh master/public/artifacts/client-agent-update.sh
install -m 0644 artifacts/versions.json master/public/artifacts/versions.json
install -m 0755 artifacts/wstunnel/leikwan-bond_linux_amd64 master/public/artifacts/wstunnel/leikwan-bond_linux_amd64
install -m 0755 artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64 master/public/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64
printf 'public hashes:\n'
sha256sum \
  sidecar-agent/sidecar_agent.py master/public/artifacts/sidecar-agent.py \
  client-agent/client_agent.py master/public/artifacts/client-agent.py \
  client-agent/install_forward_runtime.py master/public/artifacts/install-forward-runtime.py \
  client-agent/leikwan_mptcp_client.py master/public/artifacts/leikwan-mptcp-client.py \
  artifacts/versions.json master/public/artifacts/versions.json
printf 'public sizes:\n'
stat -c '%n %s %y' master/public/artifacts/sidecar-agent.py master/public/artifacts/client-agent.py master/public/artifacts/install-forward-runtime.py master/public/artifacts/leikwan-mptcp-client.py master/public/artifacts/versions.json
REMOTE