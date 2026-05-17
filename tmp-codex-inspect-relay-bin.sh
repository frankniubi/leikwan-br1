#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
cat > /tmp/codex-inspect-relay-bin.sh <<'RS'
#!/usr/bin/env bash
set +e
echo ===paths===
for p in /var/lib/leikwan-client-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-mptcp-relay /usr/local/bin/leikwan-mptcp-client.py /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install-forward-runtime.py; do
  echo ---$p---
  ls -l "$p" 2>&1
  sha256sum "$p" 2>&1
  file "$p" 2>&1
 done
echo ===relay-strings===
strings /var/lib/leikwan-client-agent/leikwan-mptcp-relay 2>/dev/null | grep -E 'bond mode|bond leg|direct_legs|bond_mode|derive_bond|relay done|sent handshake|bond session|no bond legs' | head -80
echo ===relay-help===
/var/lib/leikwan-client-agent/leikwan-mptcp-relay --help 2>&1 | head -80
echo ===live-config-key===
python3 - <<'PY'
import json
p='/etc/leikwan/mptcp-forward-47.json'
j=json.load(open(p))
print({k:j.get(k) for k in ['bond_mode','remote_host','remote_port','relay_host','relay_port','direct_legs']})
PY
echo ===running-cmd===
ps -ef | grep -E 'leikwan-mptcp-relay client --config /etc/leikwan/mptcp-forward-47|wstunnel client -L tcp://127.0.0.1:39559' | grep -v grep || true
RS
chmod +x /tmp/codex-inspect-relay-bin.sh
sshpass -p "$PASS_CLIENT" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex-inspect-relay-bin.sh root@114.80.33.50:/tmp/codex-inspect-relay-bin.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'bash /tmp/codex-inspect-relay-bin.sh'