echo '### firewall sanity'
iptables -S OUTPUT | head -4
iptables -t nat -S OUTPUT | head -4
pgrep -af blackhole.py || echo 'no blackhole running'
pgrep -af switchtest.py || echo 'no switchtest running'
echo '### ss capture'
ss -tinp 2>/dev/null | grep -A1 ultra-client > /tmp/ss.txt; wc -l /tmp/ss.txt; head -6 /tmp/ss.txt
echo '### parse'
cat > /tmp/parse.py <<'PY'
import re
totals = {}
current = None
for line in open('/tmp/ss.txt'):
    if 'bytes_acked:' in line:
        if current:
            totals[current] = totals.get(current, 0) + int(re.search(r'bytes_acked:(\d+)', line).group(1))
            current = None
        continue
    parts = line.split()
    if len(parts) >= 5 and ':' in parts[4]:
        peer = parts[4].rsplit(':', 1)[0].strip('[]').replace('::ffff:', '')
        current = peer if re.match(r'^\d{1,3}(\.\d{1,3}){3}$', peer) and not peer.startswith('127.') else None
print(totals)
PY
python3 /tmp/parse.py