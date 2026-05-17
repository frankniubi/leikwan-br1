set -euo pipefail
ROOT=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
sshpass -e ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 root@114.80.33.50 'set -e; echo === services ===; systemctl status leikwan-client-agent.service --no-pager -l || true; echo === agent unit ===; systemctl cat leikwan-client-agent.service || true; echo === recent journal ===; journalctl -u leikwan-client-agent.service -n 120 --no-pager -o short-iso || true; echo === forward47 ===; systemctl status leikwan-client-47.service --no-pager -l || true; ss -ltnp | grep -E ":(11035|2053|8899|8898)" || true; echo === versions files ===; ls -l /usr/local/bin/leikwan-client-agent.py /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json 2>/dev/null || true; sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json 2>/dev/null || true; echo === state dir ===; find /var/lib/leikwan-client-agent -maxdepth 2 -type f -printf "%TY-%Tm-%Td %TH:%TM %p %s\n" 2>/dev/null | sort | tail -80 || true'
