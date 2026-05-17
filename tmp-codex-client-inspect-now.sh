#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash -s' <<'RS'
set +e
echo '=== host/time ==='; hostname; date -Is
printf '\n=== leikwan unit files ===\n'; systemctl list-unit-files '*leikwan*' --no-pager || true
printf '\n=== installed unit paths ===\n'; ls -la /etc/systemd/system/*leikwan* /lib/systemd/system/*leikwan* 2>/dev/null || true
printf '\n=== bin files ===\n'; ls -la /usr/local/bin/*leikwan* /usr/local/bin/wstunnel /var/lib/leikwan-client-agent 2>/dev/null || true
printf '\n=== process grep ===\n'; pgrep -af 'leikwan|wstunnel|mptcp-relay' || true
printf '\n=== state dir ===\n'; find /var/lib/leikwan-client-agent -maxdepth 2 -type f -printf '%TY-%Tm-%Td %TH:%TM %p %s\n' 2>/dev/null | sort | tail -80 || true
printf '\n=== config dir ===\n'; find /etc/leikwan-client-agent /etc/leikwan -maxdepth 2 -type f -printf '%TY-%Tm-%Td %TH:%TM %p %s\n' 2>/dev/null | sort | tail -80 || true
printf '\n=== agent candidate file names ===\n'; find / -xdev \( -name '*client-agent*.py' -o -name 'client_agent.py' -o -name 'leikwan-client-agent.py' \) -printf '%p %s %TY-%Tm-%Td %TH:%TM\n' 2>/dev/null | head -50 || true
RS
