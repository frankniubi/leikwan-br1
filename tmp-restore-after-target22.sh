set +e
systemctl disable --now leikwan-client-47-onegood.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:22740' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
systemctl enable --now leikwan-client-47.service >/dev/null 2>&1 || true
sleep 2
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "f47=$(systemctl is-active leikwan-client-47.service)/$(systemctl is-enabled leikwan-client-47.service) pid=$(systemctl show -p MainPID --value leikwan-client-47.service)"
ss -ltnp | grep -E ':(11035)\b' || true