echo '### unit state'
systemctl show ultra-forward-170 -p MainPID -p NRestarts -p ActiveEnterTimestamp --value | tr '\n' ' '; echo
echo '### agent journal since 13:58'
journalctl -u leikwan-client-agent --no-pager --since '13:58' 2>/dev/null | grep -vE 'cfwg latency watchdog resolved' | tail -30
echo '### agent watchdog kills recently'
journalctl -u leikwan-client-agent --no-pager --since '-40min' 2>/dev/null | grep -c 'Watchdog timeout'
echo '### runtime ha events'
python3 -c "import json;d=json.load(open('/run/ultra/forward-170.status.json'));import itertools;print('state',d.get('state'),'ha_failures',d.get('ha_failures'),'udp_assoc',d.get('udp_active_associations'),'as_of',d.get('updated_at'));[print('  ',e) for e in (d.get('ha_events') or [])[-6:]];print('sel',json.dumps(d.get('active_udp'))[:200])"
echo '### probe tail'
tail -n 4 /var/lib/leikwan-ss-none-probe/events.jsonl
echo '### forward journal since 13:58'
journalctl -u ultra-forward-170 --no-pager --since '13:58' 2>/dev/null | tail -12