set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
: > tmp-codex-shanghai-path-probe-results.jsonl
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  [ -n "$host" ] || continue
  echo "===== PROBE $host ====="
  sshpass -p "$pass" scp "${SSH_OPTS[@]}" tmp-codex-shanghai-path-probe.py "$user@$host:/tmp/codex-shanghai-path-probe.py" >/dev/null
  out=$(sshpass -p "$pass" ssh -n "${SSH_OPTS[@]}" "$user@$host" 'python3 /tmp/codex-shanghai-path-probe.py' 2>&1 || true)
  printf '%s\n' "$out" | sed -n '1,220p'
  python3 - "$host" "$out" <<'PY' >> tmp-codex-shanghai-path-probe-results.jsonl
import sys,json,re
host=sys.argv[1]; text=sys.argv[2]
start=text.find('{')
if start>=0:
    try:
        d=json.loads(text[start:]); d['slave_host']=host; print(json.dumps(d, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'slave_host':host,'parse_error':str(e),'raw':text[:500]}))
else:
    print(json.dumps({'slave_host':host,'raw':text[:500]}))
PY
  echo
done < shanghais.txt
python3 - <<'PY'
import json
rows=[]
for line in open('tmp-codex-shanghai-path-probe-results.jsonl',encoding='utf-8'):
    d=json.loads(line); rows.append(d)
print('=== SUMMARY best cf tls/wstunnel ===')
for d in rows:
    best=[]
    for ip,v in d.get('cf',{}).items():
        curl=v.get('curl',{}); w=v.get('wstunnel',{})
        val=curl.get('min_tls') or curl.get('min_connect')
        best.append((999999 if val is None else val, ip, w.get('ok'), curl.get('statuses'), v.get('tcp2053',{}).get('min')))
    best.sort()
    print(d.get('slave_host'), 'direct8899', d.get('direct',{}).get('8899',{}).get('min'), 'direct8898', d.get('direct',{}).get('8898',{}).get('min'), 'best', best[:6])
PY