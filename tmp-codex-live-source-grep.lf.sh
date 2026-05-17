set -euo pipefail
CPASS='Frank20021212.'
CLIENT='root@114.80.33.50'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'set +e; echo date=$(date -Is); grep -n "AGENT_VERSION\|def choose_runtime_family\|cf_direct_self_leg\|target_sidecar_bond_port\|target_sidecar_mptcp_port\|def selected_mptcp_self_leg_candidates\|def mptcp_self_leg_endpoint" /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py | head -120; sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /var/lib/leikwan-client-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-bond 2>/dev/null; systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-47-onegood.service 2>/dev/null || true' 
