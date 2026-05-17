set +e
systemctl status --no-pager -l leikwan-bond-sidecar-5-iperf3-5201.service 2>&1 | sed -n '1,80p'
systemctl cat leikwan-bond-sidecar-5-iperf3-5201.service 2>/dev/null || true
ss -ltnp | grep -E ':(5201|8899|2053)\b' || true