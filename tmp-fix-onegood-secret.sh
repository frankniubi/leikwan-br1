set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47.service leikwan-client-47-onegood.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:22740' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
python3 - <<'PY'
from pathlib import Path
p=Path('/usr/local/bin/leikwan-client-start-47-onegood.sh')
s=p.read_text()
s=s.replace("SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'", "export SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'")
s=s.replace("/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT}", "/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT}")
p.write_text(s)
print(p.read_text().splitlines()[:12])
PY
systemctl daemon-reload
systemctl start leikwan-client-47-onegood.service
sleep 3
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service)/$(systemctl is-enabled leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|22740)\b' || true
journalctl -u leikwan-client-47-onegood.service -n 80 --no-pager -o short-iso | tail -80