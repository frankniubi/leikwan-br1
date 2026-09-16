echo '### start the tunnel pipe'
pkill -f '[s]spipe.py' 2>/dev/null; sleep 0.5
nohup python3 /root/sspipe.py --listen-port 15201 --target-port 5201 > /root/sspipe.log 2>&1 &
sleep 2
ss -lntp | grep 15201 || echo 'sspipe not listening'
echo '### iperf3 through the tunnel (TCP)'
timeout 60 iperf3 -c 127.0.0.1 -p 15201 -t 12 --omit 3 -J 2>/dev/null > /tmp/tunnel.json
python3 - <<'PY'
import json
try:
    d = json.load(open('/tmp/tunnel.json'))
    e = d['end']
    print('tunnel-tcp mbps=%.2f retransmits=%s' % (e['sum_received']['bits_per_second']/1e6, e.get('sum_sent',{}).get('retransmits')))
except Exception as exc:
    print('tunnel iperf3 failed:', exc)
PY
echo '### iperf3 direct to the peer (TCP)'
timeout 60 iperf3 -c 185.220.238.143 -p 5202 -t 12 --omit 3 -J 2>/dev/null > /tmp/direct.json
python3 - <<'PY'
import json
try:
    d = json.load(open('/tmp/direct.json'))
    e = d['end']
    print('direct-tcp mbps=%.2f' % (e['sum_received']['bits_per_second']/1e6))
except Exception as exc:
    print('direct iperf3 failed:', exc)
PY
echo '### udp rate curve through the tunnel (1200B payload, 8s each)'
for r in 5 20 50 100; do timeout 40 python3 /root/udprate.py --mbps $r --seconds 8; done
echo '### stop the pipe'
pkill -f '[s]spipe.py' 2>/dev/null; echo done