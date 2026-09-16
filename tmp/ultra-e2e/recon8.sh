echo '### agent error/warn counts last 24h'
journalctl -u leikwan-client-agent --no-pager --since '-24h' 2>/dev/null | grep -oE 'WARNING [^;]*|ERROR [^;]*|RuntimeError: .*' | sed 's/[0-9]\{2,\}/N/g' | sort | uniq -c | sort -rn | head -20
echo '### lease delivery traceback sample'
journalctl -u leikwan-client-agent --no-pager --since '-24h' 2>/dev/null | grep -n -B 25 'ultra lease delivery failed' | head -60
echo '### hourly count of lease-delivery failures'
journalctl -u leikwan-client-agent --no-pager --since '-24h' -o short-iso 2>/dev/null | grep 'ultra lease delivery failed' | cut -c1-13 | uniq -c | tail -25
echo '### done'