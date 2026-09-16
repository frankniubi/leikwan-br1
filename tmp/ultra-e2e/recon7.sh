echo '### failed unit count'
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'leikwan-client-agent-update-.*failed'
echo '### newest failed unit journal'
SVC=$(systemctl list-units --all --no-pager 2>/dev/null | grep 'leikwan-client-agent-update' | grep -o 'leikwan-client-agent-update-[0-9]*-[0-9]*' | tail -1)
echo "svc=$SVC"
journalctl -u "$SVC" --no-pager -n 30 2>/dev/null
echo '### newest successful-ish update units (list tail)'
systemctl list-units --all --no-pager 2>/dev/null | grep 'leikwan-client-agent-update' | tail -3
echo '### update chain journal (agent)'
journalctl -u leikwan-client-agent --no-pager --since '-40min' 2>/dev/null | grep -iE 'update|runtime|installer|token' | tail -25
echo '### done'