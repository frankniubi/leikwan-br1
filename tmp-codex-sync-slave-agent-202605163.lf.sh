#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PANEL_PASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)

echo '=== sync panel slave-agent + manifest ==='
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py artifacts/versions.json "$PANEL:/tmp/" >/dev/null
sshpass -p "$PANEL_PASS" ssh -n "${SSH_OPTS[@]}" "$PANEL" 'set -euo pipefail
  cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
  install -m 0644 /tmp/slave_agent.py slave-agent/slave_agent.py
  install -m 0644 /tmp/versions.json artifacts/versions.json
  python3 -m py_compile slave-agent/slave_agent.py
  python3 - <<"PY"
import json, pathlib, hashlib, re
p=pathlib.Path("slave-agent/slave_agent.py")
vp=pathlib.Path("artifacts/versions.json")
v=json.loads(vp.read_text())
text=p.read_text()
m=re.search(r"AGENT_VERSION\s*=\s*[\"'"'"']([^\"'"'"']+)", text)
print("panel_agent_version", m.group(1) if m else "missing")
print("panel_agent_sha", hashlib.sha256(p.read_bytes()).hexdigest())
print("manifest", v["slave_agent"])
PY
'

echo '=== sync Shanghai slaves, keep only endpoint317 slave-agent disabled ==='
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  host=${host%$'\r'}; user=${user%$'\r'}; pass=${pass%$'\r'}
  [ -n "$host" ] || continue
  echo "--- $host ---"
  sshpass -p "$pass" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py "$user@$host:/usr/local/bin/leikwan-slave-agent.py.tmp" >/dev/null
  if [ "$host" = "47.116.104.15" ]; then
    sshpass -p "$pass" ssh -n "${SSH_OPTS[@]}" "$user@$host" 'set -euo pipefail
      install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
      rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
      python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
      grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
      systemctl disable --now leikwan-slave-agent.service >/dev/null 2>&1 || true
      echo "slave_agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null || true)"
    '
  else
    sshpass -p "$pass" ssh -n "${SSH_OPTS[@]}" "$user@$host" 'set -euo pipefail
      install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
      rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
      python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
      grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
      rm -f /var/lib/leikwan-slave/cf-location-best-ip.json
      systemctl restart leikwan-slave-agent.service
      sleep 2
      echo "slave_agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null || true)"
    '
  fi
done < shanghais.txt

