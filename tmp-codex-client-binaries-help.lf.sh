set -euo pipefail
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
echo binaries
for b in /usr/local/bin/leikwan-bond /var/lib/leikwan-client-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-ingress; do
  echo --$b--
  [ -x "$b" ] && ($b --help 2>&1 | head -30 || true)
  sha256sum "$b" 2>/dev/null || true
  strings "$b" 2>/dev/null | grep -a -E 'failed to connect upstream target|recv_reset|invalid bond frame magic|bond client TCP transport' | head -10 || true
done
REMOTE