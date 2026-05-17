sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set +e
ls -la /tmp/codex-single-slave-iperf47
for f in /tmp/codex-single-slave-iperf47/*; do echo "---$f---"; sed -n '1,120p' "$f" | tail -120; done
REMOTE