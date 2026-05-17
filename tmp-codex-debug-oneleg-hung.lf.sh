set -euo pipefail
sshpass -p 'Frank20021212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set +e
echo "time=$(date -Is)"
ps -eo pid,etimes,cmd | grep -E '2915[01]|codex-oneleg|iperf3|leikwan-bond client --protocol tcp --listen 127.0.0.1:29151|leikwan-ingress client --listen 127.0.0.1:29150' | grep -v grep
ss -ltnp | grep -E ':(29150|29151|11035)' || true
ls -la /tmp/codex-oneleg-316-iperf 2>/dev/null
for f in /tmp/codex-oneleg-316-iperf/*; do echo ---$f; tail -n 80 "$f" 2>/dev/null; done
REMOTE