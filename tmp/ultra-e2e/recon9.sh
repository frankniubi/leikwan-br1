J='journalctl -u leikwan-client-agent --no-pager --since -24h'
echo '### lease grant unavailable by forward'
$J 2>/dev/null | grep 'Ultra lease grant unavailable' | grep -oE 'forward [0-9]+' | sort | uniq -c
echo '### refresh failed by forward'
$J 2>/dev/null | grep 'Ultra refresh failed' | grep -oE 'forward [0-9]+' | sort | uniq -c
echo '### listener absent by forward'
$J 2>/dev/null | grep 'Ultra listener absent' | grep -oE 'ultra-forward-[0-9]+' | sort | uniq -c
echo '### listener-absent restart times (last 30)'
$J 2>/dev/null | grep 'Ultra listener absent' | awk '{print $1,$2,$3}' | tail -30
echo '### lease delivery failure reasons'
$J 2>/dev/null | grep -oE 'Ultra lease delivery failed for forward [0-9]+: [a-z_]+' | sort | uniq -c | sort -rn | head
echo '### agent watchdog kills'
$J 2>/dev/null | grep -c 'Watchdog timeout'
echo '### restarts of ultra-forward-170 (systemd)'
journalctl -u ultra-forward-170 --no-pager --since -24h 2>/dev/null | grep -cE 'Started|Stopped'
journalctl -u ultra-forward-170 --no-pager --since -24h -o short-iso 2>/dev/null | grep -E 'Started|Stopped|Killed|Failed' | tail -20
echo '### done'