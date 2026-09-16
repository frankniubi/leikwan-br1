echo '### ultra-client --version'
/usr/local/bin/ultra-client --version 2>&1 | head -5; echo "rc=$?"
echo '### ultra-client sha + mtime'
sha256sum /usr/local/bin/ultra-client; ls -l /usr/local/bin/ultra-client*
echo '### ultra unit 170'
systemctl cat ultra-forward-170.service 2>/dev/null | sed -n '1,25p'
echo '### probe script head'
ls -l /usr/local/libexec/leikwan-ss-none-probe/; sed -n '1,60p' /usr/local/libexec/leikwan-ss-none-probe/ss-none-availability-probe.py
echo '### probe failure distribution (24h)'
python3 - <<'PY'
import json,collections
c=collections.Counter(); hours=collections.Counter()
for line in open('/var/lib/leikwan-ss-none-probe/events.jsonl'):
    try: e=json.loads(line)
    except Exception: continue
    if not e.get('ok'):
        c[(e.get('error_code',''), e.get('tcp_error_code',''), e.get('udp_error_code',''))]+=1
        hours[e.get('at','')[:13]]+=1
print('fail combos:', c.most_common(8))
print('total samples:', sum(1 for _ in open('/var/lib/leikwan-ss-none-probe/events.jsonl')))
print('fails by hour (last 24):')
for h in sorted(hours)[-24:]: print('  ',h,hours[h])
PY
echo '### done'