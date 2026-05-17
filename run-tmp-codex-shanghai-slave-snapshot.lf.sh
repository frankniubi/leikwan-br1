#!/usr/bin/env bash
set -euo pipefail
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=4 -o ServerAliveCountMax=1)
cat > /tmp/codex-sh-slaves.tsv <<'EOF'
316|47.116.11.144|@XwDQ6H5mgMXbrGD
317|47.116.104.15|U3I+sVL^%IXyqMX0
318|101.133.142.65|b+z@Qk860a+6UsVW
319|47.116.134.3|RQRUbOkeh7ZpCkDa
320|8.133.170.123|TnJKTGse+JMJFR%t
EOF
while IFS='|' read -r eid host pass; do
  echo "===== endpoint $eid host $host $(date -Is) ====="
  sshpass -p "$pass" ssh "${SSH[@]}" root@$host "EID=$eid bash -s" <<'REMOTE' || true
set +e
echo "host=$(hostname) now=$(date -Is)"
for u in leikwan-slave-agent.service wstunnel-svc-2-${EID}.service leikwan-tcp-mux-7000.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners --'; ss -ltnp | grep -E ':(7000|7001|7002|7003|7329|7330)\b' || true
echo '-- metadata --'
python3 - <<'PY'
import json, os, pathlib
E=os.environ['EID']
for p in [pathlib.Path(f'/var/lib/leikwan-slave/services/wstunnel-svc-2-{E}.json'), pathlib.Path('/var/lib/leikwan-slave/routes-7000.json')]:
 print('---',p)
 if not p.exists(): print('missing'); continue
 try: d=json.loads(p.read_text())
 except Exception as e: print('jsonerr',e); print(p.read_text(errors='ignore')[:600]); continue
 if isinstance(d,dict) and 'routes' not in d:
  print(json.dumps({k:d.get(k) for k in ['service_name','forward_id','endpoint_id','listen_port','backend_port','target','selected_cf_ip','ingress_protocol','backend_protocol','backend_mode','transport_mode','relay_target']},ensure_ascii=False,indent=2))
  print('cf_mode', json.dumps(d.get('cf_mode',{}), ensure_ascii=False)[:900])
 else:
  print(json.dumps(d, ensure_ascii=False)[:1200])
PY
echo '-- cmdline --'; pid=$(systemctl show -p MainPID --value wstunnel-svc-2-${EID}.service 2>/dev/null); [ -n "$pid" ] && [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
echo '-- edge tcp ms selected + candidates --'
python3 - <<'PY'
import json, pathlib, os, socket, time, statistics
E=os.environ['EID']; p=pathlib.Path(f'/var/lib/leikwan-slave/services/wstunnel-svc-2-{E}.json')
cands=[]
if p.exists():
 try:
  d=json.loads(p.read_text());
  if d.get('selected_cf_ip'): cands.append(str(d.get('selected_cf_ip')))
  cf=d.get('cf_mode') or {}
  for cidr in cf.get('cidrs') or []:
   base=cidr.split('/')[0].rsplit('.',1)[0]
   for last in [3,4,6,7,8,29,39,78,100,200]: cands.append(base+'.'+str(last))
 except Exception: pass
for ip in list(dict.fromkeys(cands))[:20]:
 vals=[]; errs=[]
 for i in range(3):
  t=time.time()
  try:
   s=socket.create_connection((ip,2053),timeout=1.2); vals.append((time.time()-t)*1000); s.close()
  except Exception as e: errs.append(type(e).__name__)
 print(ip,'ok',len(vals),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:2])
PY
echo '-- recent counts 30m --'
for pat in 'Invalid status code: 520' 'failed to do websocket handshake' 'Opening TCP connection' 'Closing local' 'Unexpected EOF' 'error' 'WARN'; do c=$(journalctl -u wstunnel-svc-2-${EID}.service -u leikwan-tcp-mux-7000.service --since '-30 min' --no-pager 2>/dev/null | grep -c "$pat" || true); echo "$pat=$c"; done
REMOTE
  echo
done < /tmp/codex-sh-slaves.tsv