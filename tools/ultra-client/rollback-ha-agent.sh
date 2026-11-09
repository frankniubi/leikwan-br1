#!/usr/bin/env bash
# Target-specific rollback; restores the old Agent, not its original defects.
set -euo pipefail
test "$(hostname)" = instance-20241020-0131
test "$(id -u)" = 0
backup=/root/ultra-ha-20260911-1
override=/etc/systemd/system/leikwan-client-agent.service.d/90-ha-canary.conf
echo '995d53ec3ad71fb9439752492aba1350068b39f6508861c2346a4d15a20b923d  /usr/local/bin/leikwan-client-agent.py' | sha256sum -c -
echo "54d14675149a53155921b1cc5328ea1e5b843039babc7b2431c96284342dfa3c  $backup/client-agent.previous.py" | sha256sum -c -
test -f "$override"
test ! -e "$backup/agent-canary.conf.rolled-back"
python3 -m py_compile "$backup/client-agent.previous.py"
install -m 755 "$backup/client-agent.previous.py" /usr/local/bin/leikwan-client-agent.py.rollback
mv /usr/local/bin/leikwan-client-agent.py.rollback /usr/local/bin/leikwan-client-agent.py
mv "$override" "$backup/agent-canary.conf.rolled-back"
systemctl daemon-reload
systemctl restart leikwan-client-agent
systemctl is-active leikwan-client-agent
