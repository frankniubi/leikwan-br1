echo '### iperf3 through the tunnel (plain output, tail)'
timeout 60 iperf3 -c 127.0.0.1 -p 15201 -t 10 --omit 3 2>&1 | tail -5
echo '### iperf3 direct to the peer'
timeout 60 iperf3 -c 185.220.238.143 -p 5202 -t 10 --omit 3 2>&1 | tail -4
echo '### udp rate curve through the tunnel (1200B payload, 8s each)'
for r in 5 20 50 100; do timeout 40 python3 /root/udprate.py --mbps $r --seconds 8; done