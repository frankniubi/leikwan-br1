echo '### start the tunnel pipe as a unit'
systemctl reset-failed sspipe.service 2>/dev/null
systemd-run --unit=sspipe --collect /usr/bin/python3 /root/sspipe.py --listen-port 15201 --target-port 5201 2>&1 | tail -1
sleep 2
ss -lntp | grep 15201 || echo 'sspipe not listening'
echo '### iperf3 through the tunnel (TCP)'
timeout 60 iperf3 -c 127.0.0.1 -p 15201 -t 12 --omit 3 -J > /tmp/tunnel.json 2>/tmp/tunnel.err
python3 - <<'PY'
import json
try:
    d = json.load(open('/tmp/tunnel.json'))
    e = d['end']
    print('tunnel-tcp mbps=%.2f retransmits=%s' % (e['sum_received']['bits_per_second']/1e6, e.get('sum_sent',{}).get('retransmits')))
except Exception as exc:
    print('tunnel iperf3 failed:', exc, open('/tmp/tunnel.err').read()[:200])
PY