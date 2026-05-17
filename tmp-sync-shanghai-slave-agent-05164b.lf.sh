set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1)
mapfile -t rows < shanghais.txt
for row in "${rows[@]}"; do
  IFS='|' read -r host region user pass rest <<<"$row"
  [ -n "${host:-}" ] || continue
  echo "--- $host ---"
  sshpass -p "$pass" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py "$user@$host:/usr/local/bin/leikwan-slave-agent.py.tmp" </dev/null >/dev/null
  if [ "$host" = "47.116.104.15" ]; then
    sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'set -euo pipefail
      install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
      rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
      python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
      grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
      rm -f /var/lib/leikwan-slave/cf-location-best-ip.json
      systemctl enable --now leikwan-slave-agent.service >/dev/null 2>&1 || true
      systemctl restart leikwan-slave-agent.service
      sleep 5
      echo agent=$(systemctl is-active leikwan-slave-agent.service)/$(systemctl is-enabled leikwan-slave-agent.service)
      python3 - <<"PY"
import json,pathlib
for p in [pathlib.Path("/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json"), pathlib.Path("/var/lib/leikwan-slave/cf-location-best-ip.json")]:
 print("---",p)
 if p.exists(): print(p.read_text()[:2500])
PY
      pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service); echo wst_pid=$pid; [ "$pid" != 0 ] && tr "\0" " " </proc/$pid/cmdline; echo
    ' </dev/null
  else
    sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'set -euo pipefail
      install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
      rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
      python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
      grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
      echo agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null || true)
    ' </dev/null
  fi
done