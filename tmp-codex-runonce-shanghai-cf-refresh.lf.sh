#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  host=${host%$'\r'}; user=${user%$'\r'}; pass=${pass%$'\r'}
  [ -n "$host" ] || continue
  echo "--- $host ---"
  if [ "$host" = "47.116.104.15" ]; then
    echo "skipping disabled one-good endpoint317"
    continue
  fi
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'bash -s' <<'REMOTE'
set +e
rm -f /var/lib/leikwan-slave/cf-location-best-ip.json
/usr/bin/python3 /usr/local/bin/leikwan-slave-agent.py --config /etc/leikwan-slave/config.json run-once >/tmp/codex-runonce.log 2>&1 || true
sleep 1
python3 - <<'PY'
import json,pathlib
print('log_tail:')
print(pathlib.Path('/tmp/codex-runonce.log').read_text(errors='ignore')[-1200:])
for p in pathlib.Path('/var/lib/leikwan-slave/services').glob('wstunnel-svc-2-*.json'):
    try: d=json.loads(p.read_text())
    except Exception: continue
    if int(d.get('forward_id') or 0)==47:
        print(json.dumps({k:d.get(k) for k in ['service_name','endpoint_id','selected_cf_ip','backend_port','target','backend_protocol']}, ensure_ascii=False))
        break
PY
REMOTE
done < shanghais.txt
