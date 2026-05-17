set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8)

echo "=== client forward47 leg map ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  date -Is
  sed -n "1,80p" /usr/local/bin/leikwan-client-start-47.sh
  echo "--- current bond warnings last 80 ---"
  journalctl -u leikwan-client-47.service --since "-5 min" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|session established|no bond legs|Invalid status code|Opening TCP connection" | tail -80 || true
'

declare -A PASS
PASS[47.116.11.144]='@XwDQ6H5mgMXbrGD'
PASS[47.116.134.3]='RQRUbOkeh7ZpCkDa'
PASS[47.116.104.15]='U3I+sVL^%IXyqMX0'
PASS[8.133.170.123]='TnJKTGse+JMJFR%t'
PASS[101.133.142.65]='b+z@Qk860a+6UsVW'

declare -A EID
EID[47.116.11.144]=316
EID[47.116.104.15]=317
EID[101.133.142.65]=318
EID[47.116.134.3]=319
EID[8.133.170.123]=320

for h in 47.116.104.15 101.133.142.65 47.116.134.3 8.133.170.123 47.116.11.144; do
  eid=${EID[$h]}
  echo "=== slave $h endpoint $eid ==="
  sshpass -p "${PASS[$h]}" ssh "${SSH_OPTS[@]}" root@$h "EID=$eid bash -s" <<'RS'
set +e
svc="wstunnel-svc-2-${EID}"
export svc
echo "date=$(date -Is) host=$(hostname) svc=$svc"
echo "-- agent version/status --"
grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py 2>/dev/null | head -1 || true
systemctl is-active leikwan-slave-agent.service 2>/dev/null || true
systemctl is-active "$svc.service" 2>/dev/null || true
echo "-- unit --"
sed -n '1,80p' "/etc/systemd/system/$svc.service" 2>/dev/null || true
echo "-- metadata --"
python3 - <<'PY' 2>/dev/null || cat "/var/lib/leikwan-slave/services/$svc.json" 2>/dev/null || true
import json, pathlib, os
p=pathlib.Path(f"/var/lib/leikwan-slave/services/{os.environ['svc']}.json")
if p.exists():
  d=json.loads(p.read_text())
  for k in ["service_name","endpoint_id","public_port","backend_port","target","relay_target","ingress_protocol","backend_protocol","backend_mode","path_prefix","route_key","selected_cf_ip","cf_mode"]:
    print(f"{k}={d.get(k)!r}")
PY
echo "-- route entry --"
python3 - <<'PY' 2>/dev/null || grep -n "07289d1f5ed25399" /var/lib/leikwan-slave/listeners/routes-7000.json || true
import json
p='/var/lib/leikwan-slave/listeners/routes-7000.json'
d=json.load(open(p))
for r in d.get('routes',[]):
  if r.get('route_key')=='07289d1f5ed25399' or r.get('path_prefix')=='07289d1f5ed25399':
    print(r)
PY
echo "-- listeners/processes --"
ss -ltnp | grep -E ':(7000|700[0-9]|701[0-9]|8899|8898|2053)' || true
ps -eo pid,etimes,cmd | grep -E "$svc|leikwan-ingress mux|leikwan-ingress backend|wstunnel server|wstunnel client" | grep -v grep || true
echo "-- recent relevant logs --"
journalctl -u "$svc.service" -u leikwan-tcp-mux-7000.service --since '-3 min' --no-pager -o short-iso | grep -E 'Parse\(Method\)|invalid|Opening TCP|backend|route|Accepting|Error' | tail -50 || true
RS
done