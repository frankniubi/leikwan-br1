echo '### agent update state'
python3 -c "import json;d=json.load(open('/var/lib/leikwan-client-agent/agent_update_state.json'));print(d)"
echo '### any update units now'
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'leikwan-client-agent-update'
echo '### agent log last 6'
journalctl -u leikwan-client-agent --no-pager -n 6 2>/dev/null | tail -6
echo '### running binary check'
for f in 158 159 170 171 180; do pid=$(systemctl show ultra-forward-$f -p MainPID --value); printf 'forward-%s pid=%s exe_sha=' $f $pid; sha256sum /proc/$pid/exe 2>/dev/null | cut -c1-16; done