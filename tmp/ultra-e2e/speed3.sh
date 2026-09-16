echo "### udp rate curve (1200B payload, 8s each)"
for r in 5 20 50 100; do timeout 40 python3 /root/udprate2.py --mbps $r --seconds 8; done