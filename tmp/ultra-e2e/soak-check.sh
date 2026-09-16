D=$(cat /root/soak-current-dir)
echo "dir=$D"
cat $D/progress.json; echo
date -u +%H:%M:%SZ
echo '--- unit restarts so far ---'
systemctl show ultra-forward-170 -p NRestarts --value
echo '--- probe events during soak ---'
tail -n 5 /var/lib/leikwan-ss-none-probe/events.jsonl
echo '--- runtime status ---'
python3 -c "import json;d=json.load(open('/run/ultra/forward-170.status.json'));print({k:d.get(k) for k in ['state','ha_failures','udp_active_associations','udp_keepalive_acks','bytes_in','bytes_out','updated_at']})"