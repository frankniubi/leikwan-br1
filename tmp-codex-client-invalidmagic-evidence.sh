set -euo pipefail
PASS='Frank20021212.'
CLIENT='114.80.33.50'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$CLIENT 'set +e
echo === client scripts 47/59 ===
for f in /usr/local/bin/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-start-59.sh; do echo ---$f; grep -nE "leikwan-bond client|leikwan-ingress client|wstunnel client|session-token|route-key|auth-token|--legs" "$f" 2>/dev/null | sed -n "1,80p"; done
echo === local listeners ===
ss -ltnp | grep -E ":(11035|32501|21739|21740|2218|2238)" || true
echo === invalid/recent logs ===
journalctl -u leikwan-client-47.service -u leikwan-client-59.service --since "-8 min" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|no bond legs|TCP transport session established|Opening TCP connection|Invalid status code" | tail -160 || true
' 
