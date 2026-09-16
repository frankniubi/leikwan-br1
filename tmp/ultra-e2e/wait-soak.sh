python3 -m py_compile /root/soak-digest.py && echo digest_ok
D=$(cat /root/soak-current-dir)
echo "dir=$D"
for i in $(seq 1 80); do [ -f "$D/summary.json" ] && break; sleep 15; done
ls -la $D
date -u +%H:%M:%SZ
python3 /root/soak-digest.py $D/summary.json
echo '### unit restart count'
systemctl show ultra-forward-170 -p NRestarts --value
echo '### agent watchdog kills during soak'
journalctl -u leikwan-client-agent --no-pager --since '-35min' 2>/dev/null | grep -c 'Watchdog timeout'