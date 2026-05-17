#!/usr/bin/env bash
set -euo pipefail
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  [ -n "${host:-}" ] || continue
  echo "=== $host $region ==="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'bash -s' <<'REMOTE'
set +e
for ep in 316 317 318 319 320; do
  f=/var/lib/leikwan-slave/services/wstunnel-svc-2-$ep.json
  [ -e "$f" ] || continue
  echo "-- ep=$ep --"
  python3 - <<PY
import json, pathlib
p=pathlib.Path('$f')
d=json.loads(p.read_text())
print({'service':d.get('service_name'),'status':d.get('status'),'target':d.get('target'),'backend_port':d.get('backend_port'),'selected_cf_ip':d.get('selected_cf_ip'),'auth':d.get('ingress_auth_token')[:8] if d.get('ingress_auth_token') else None})
PY
  systemctl is-active wstunnel-svc-2-$ep.service 2>/dev/null || true
  pid=$(systemctl show -p MainPID --value wstunnel-svc-2-$ep.service 2>/dev/null); [ -n "$pid" ] && [ "$pid" != 0 ] && { tr '\0' ' ' </proc/$pid/cmdline; echo; }
done
ss -ltnp | grep ':7000 ' || true
REMOTE
  echo
 done < shanghais.txt
