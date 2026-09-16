echo '### failed units before'
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'failed'
systemctl reset-failed
echo '### failed units after'
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'failed'
systemctl --failed --no-pager 2>/dev/null | tail -3
echo '### soak progress'
cat $(cat /root/soak-current-dir)/progress.json; echo
echo '### probe last 6'
tail -n 6 /var/lib/leikwan-ss-none-probe/events.jsonl