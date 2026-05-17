set +e
echo "--- mptcp47 raw first 240 ---"
python3 - <<'PY'
from pathlib import Path
p=Path('/etc/leikwan/mptcp-forward-47.json')
b=p.read_bytes() if p.exists() else b''
print('exists', p.exists(), 'len', len(b), 'head', b[:80])
print(b[:2000].decode('utf-8','replace'))
PY
echo "--- jq parse err ---"
jq . /etc/leikwan/mptcp-forward-47.json >/tmp/jq47.out 2>/tmp/jq47.err; echo jq_rc=$?; cat /tmp/jq47.err; sed -n '1,120p' /tmp/jq47.out
echo "--- journal f47 recent ---"
journalctl -u leikwan-client-47.service --no-pager -n 180 | tail -180 || true
echo "--- unit f47 ---"
systemctl cat leikwan-client-47.service || true