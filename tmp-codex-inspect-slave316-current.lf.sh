#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "=== slave316 state $(hostname) $(date -Is) ==="
for u in leikwan-slave-agent.service leikwan-tcp-mux-7000.service wstunnel-svc-2-316.service wstunnel-svc-2-317.service wstunnel-svc-2-318.service wstunnel-svc-2-319.service wstunnel-svc-2-320.service; do
  printf '%-38s ' "$u"; systemctl is-active "$u" 2>/dev/null || true
  systemctl show -p MainPID -p ActiveState -p SubState "$u" 2>/dev/null | tr '\n' ' '; echo
done

echo "=== endpoint316 files ==="
for f in /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json /var/lib/leikwan-slave/listeners/routes-7000.json /etc/systemd/system/wstunnel-svc-2-316.service /etc/systemd/system/leikwan-tcp-mux-7000.service; do
  echo "--- $f ---"
  if [ -f "$f" ]; then
    case "$f" in
      *.json) python3 -m json.tool "$f" 2>/dev/null | sed -n '1,220p' || sed -n '1,220p' "$f" ;;
      *) sed -n '1,180p' "$f" ;;
    esac
  else echo missing; fi
done

echo "=== listeners/procs ==="
ss -ltnp | grep -E ':(7000|7013|700[0-9]|7329|8899|2053)' || true
ps -eo pid,etimes,cmd | grep -E 'leikwan-ingress mux|leikwan-ingress backend|wstunnel client|wstunnel server|wstunnel-svc-2-316|leikwan-slave-agent' | grep -v grep | sed -n '1,220p'

echo "=== route316 selected fields ==="
python3 - <<'PY'
import json, pathlib
for p in ['/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json','/var/lib/leikwan-slave/listeners/routes-7000.json']:
    print('FILE', p)
    try: d=json.load(open(p))
    except Exception as e: print('ERR',e); continue
    if isinstance(d, dict) and 'routes' in d:
        for r in d['routes']:
            if r.get('route_key')=='07289d1f5ed25399' or r.get('path_prefix')=='07289d1f5ed25399' or r.get('service_name')=='wstunnel-svc-2-316':
                print(json.dumps(r, ensure_ascii=False, indent=2))
    else:
        keys=['service_name','endpoint_id','target','relay_target','backend_port','backend_protocol','ingress_protocol','selected_cf_ip','path_prefix','route_key','cf_mode']
        print(json.dumps({k:d.get(k) for k in keys}, ensure_ascii=False, indent=2))
PY

echo "=== recent logs key ==="
journalctl -u leikwan-slave-agent.service -u leikwan-tcp-mux-7000.service -u wstunnel-svc-2-316.service --since '-30 min' --no-pager -o short-iso | grep -E 'plain tcp mux|mux connection|route|backend|wstunnel|Invalid status|520|Opening TCP|handshake|failed|error|WARN|ERROR|leikwan-ingress' | tail -240 || true
REMOTE
