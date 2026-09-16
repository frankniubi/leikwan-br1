for f in /etc/ultra/forward-158.json /etc/ultra/forward-170.json /etc/ultra/forward-171.json /etc/ultra/forward-180.json; do echo "### $f"; cat $f; echo; done
echo '### ss-none-probe config'
cat /etc/leikwan-ss-none-probe/config.json; echo
echo '### ss-none status'
cat /var/lib/leikwan-ss-none-probe/status.json; echo
echo '### ss-none last events'
tail -n 2 /var/lib/leikwan-ss-none-probe/events.jsonl
echo '### agent config'
cat /etc/leikwan-client-agent/config.json; echo
echo '### agent update state'
cat /var/lib/leikwan-client-agent/agent_update_state.json; echo
echo '### config pull state'
cat /var/lib/leikwan-client-agent/config_pull_state.json; echo
echo '### tcp listeners'
ss -lntp 2>/dev/null | sed -n '1,25p'
echo '### port 1000x'
ss -lntup 2>/dev/null | grep -E ':100[0-9][0-9]' || echo 'nothing on 100xx'
echo '### brook cmdline'
tr '\0' ' ' < /proc/1766/cmdline; echo
echo '### ss-none unit'
systemctl cat leikwan-ss-none-probe.service 2>/dev/null | sed -n '1,30p'
echo '### latest update svc journal'
SVC=$(systemctl list-units --all --no-pager 2>/dev/null | grep -o 'leikwan-client-agent-update-[0-9]*-[0-9]*' | tail -1); echo "svc=$SVC"; journalctl -u "$SVC" -n 40 --no-pager 2>/dev/null | tail -n 40
echo '### done'