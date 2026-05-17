set -euo pipefail
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
cat >/tmp/codex_bond_helper_probe.sh <<'RS'
set +e
SCRIPT=/usr/local/bin/leikwan-client-start-47.sh
TOKEN=$(python3 - <<'PY'
import re,pathlib
s=pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh').read_text()
m=re.search(r"--session-token '([^']+)'", s)
print(m.group(1) if m else '')
PY
)
AUTH=$(grep -oE -- '--auth-token [0-9a-f]+' "$SCRIPT" | head -1 | awk '{print $2}')
ROUTE=07289d1f5ed25399
REMOTE=47.116.11.144:7000
lp=$(python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
)
bp=$(python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
)
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${lp} --remote ${REMOTE} --route-key ${ROUTE} --auth-token ${AUTH} >/tmp/codex-ingress-helper.log 2>&1 & ipid=$!
sleep 0.4
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${bp} --legs "tcp://127.0.0.1:${lp}?priority=10&endpoint_id=316&display_host=47.116.11.144" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 400 >/tmp/codex-bond-helper.log 2>&1 & bpid=$!
sleep 4
cat /tmp/codex-bond-helper.log | grep -E 'established|invalid_token|failed to connect initial|failed to establish|early eof|Connection reset' | tail -20 || true
cat /tmp/codex-ingress-helper.log | tail -20 || true
kill $bpid $ipid 2>/dev/null; wait $bpid $ipid 2>/dev/null
RS
sshpass -e scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no /tmp/codex_bond_helper_probe.sh root@114.80.33.50:/tmp/codex_bond_helper_probe.sh >/dev/null
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@114.80.33.50 'bash /tmp/codex_bond_helper_probe.sh'
