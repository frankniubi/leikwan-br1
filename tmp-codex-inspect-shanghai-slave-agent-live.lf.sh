#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  host=${host%$'\r'}; region=${region%$'\r'}; user=${user%$'\r'}; pass=${pass%$'\r'}
  [ -n "$host" ] || continue
  case "$region" in *??*|*Shanghai*|*shanghai*) ;; *) continue;; esac
  echo "=== $host $region ==="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'bash -s' <<'REMOTE' || true
set +e
echo "agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null || true) enabled=$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null || true)"
python3 - <<'PY'
import pathlib,re,json
for p in ['/usr/local/bin/leikwan-slave-agent.py','/opt/leikwan/slave-agent/slave_agent.py']:
    path=pathlib.Path(p)
    if not path.exists(): continue
    t=path.read_text(errors='ignore')
    m=re.search(r'AGENT_VERSION\s*=\s*["\']([^"\']+)',t)
    print('file',p,'version',m.group(1) if m else '?','has_validate_current',('validate_current' in t),'reconcile_only_empty',('if selected_ip == "":' in t[t.find('elif backend_mode == "cf_mode"'):t.find('def process_tasks')] if 'elif backend_mode == "cf_mode"' in t else '?'))
    break
print('cf_state:')
sp=pathlib.Path('/var/lib/leikwan-slave/cf-location-best-ip.json')
if sp.exists():
    try: print(json.dumps(json.loads(sp.read_text()), ensure_ascii=False)[:1200])
    except Exception as e: print('bad',e)
print('f47 metas:')
for p in pathlib.Path('/var/lib/leikwan-slave/services').glob('wstunnel-svc-2-*.json'):
    try: d=json.loads(p.read_text())
    except Exception: continue
    if int(d.get('forward_id') or 0)==47:
        print(json.dumps({k:d.get(k) for k in ['service_name','endpoint_id','selected_cf_ip','backend_port','target','backend_mode','backend_protocol','ingress_protocol']}, ensure_ascii=False))
PY
REMOTE
done < shanghais.txt

