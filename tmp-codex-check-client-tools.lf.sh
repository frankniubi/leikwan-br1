set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
command -v iperf3 || true
command -v /usr/local/bin/leikwan-ingress || true
command -v /usr/local/bin/leikwan-bond || true
command -v /usr/local/bin/wstunnel || true
systemctl is-active leikwan-client-agent.service 2>/dev/null || true
REMOTE
