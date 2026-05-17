#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PANEL_PASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== sync panel ProvisioningService ==='
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" master/src/Support/ProvisioningService.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -l master/src/Support/ProvisioningService.php && sha256sum master/src/Support/ProvisioningService.php'

echo '=== publish slave agent artifact ==='
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/slave-agent/slave_agent.py" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && python3 -m py_compile slave-agent/slave_agent.py && sha256sum slave-agent/slave_agent.py && grep -n "AGENT_VERSION" slave-agent/slave_agent.py | head -1'

echo '=== sync hotfix to Shanghai slaves and restart ==='
while IFS='|' read -r host region user pass; do
  [ -z "${host:-}" ] && continue
  echo "--- $host ---"
  sshpass -p "$pass" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py "$user@$host:/usr/local/bin/leikwan-slave-agent.py.tmp" >/dev/null
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'set -euo pipefail
    install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
    rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
    python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
    grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
    systemctl restart leikwan-slave-agent.service
    sleep 1
    systemctl is-active leikwan-slave-agent.service
  '
done < shanghais.txt
