echo '### stop test units'
systemctl stop sspipe.service 2>/dev/null; systemctl reset-failed sspipe.service 2>/dev/null; echo sspipe-stopped
echo '### forward state'
/usr/local/bin/ultra-client --version
for f in 158 159 170 171 180; do printf '%s=%s ' $f $(systemctl is-active ultra-forward-$f); done; echo
echo '### failed units'
systemctl --failed --no-pager 2>/dev/null | tail -2
echo '### iptables leftovers'
iptables -S OUTPUT | head -3; iptables -t nat -S OUTPUT | head -3
echo '### agent update state'
python3 -c "import json;d=json.load(open('/var/lib/leikwan-client-agent/config_pull_state.json'));print('token', d.get('agent_update_request_token'))" 2>/dev/null