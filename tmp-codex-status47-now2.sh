set -euo pipefail
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@114.80.33.50 'set +e
echo ===status===
systemctl is-active leikwan-client-47.service leikwan-client-agent.service
systemctl status leikwan-client-47.service --no-pager -l | sed -n "1,45p"
echo ===agent last===
journalctl -u leikwan-client-agent.service --since "-3 min" --no-pager | grep -E "forward 47|restarted leikwan-client-47|applied forward 47" | tail -30
echo ===f47 last===
journalctl -u leikwan-client-47.service --since "-2 min" --no-pager | grep -E "Invalid status code|established|failed to connect initial|failed to establish|recv_reset|Connection refused" | tail -60
echo ===script line===
grep wstunnel /usr/local/bin/leikwan-client-start-47.sh
echo ===cache===
cat /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json'
