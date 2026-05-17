set +e
echo '--- client direct to sidecar public ---'
for p in 22 2053 8899 8898 5201; do timeout 3 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1; echo "185.220.238.143:$p rc=$?"; done
echo '--- iperf sidecar public reverse ---'
timeout 15 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --connect-timeout 3000 2>&1 | sed -n '1,80p'; echo iperf_rc=${PIPESTATUS[0]}
echo '--- direct wst test via CF edge to target 8899 then iperf ---'
pkill -f 'wstunnel.*127.0.0.1:23999' 2>/dev/null || true
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:23999:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix 07289d1f5ed25399 wss://162.159.39.4:2053 >/tmp/codex-wst23999.log 2>&1 & echo $! >/tmp/codex-wst23999.pid
for i in $(seq 1 30); do timeout .2 bash -lc '</dev/tcp/127.0.0.1/23999' >/dev/null 2>&1 && break || sleep .1; done
ss -ltnp | grep 23999 || true
sleep .5
timeout 15 iperf3 -c 127.0.0.1 -p 23999 -R -b 300M -t 5 --connect-timeout 3000 2>&1 | sed -n '1,100p'; echo wst_iperf_rc=${PIPESTATUS[0]}
echo '--- wst log ---'; cat /tmp/codex-wst23999.log | tail -80
kill $(cat /tmp/codex-wst23999.pid) 2>/dev/null || true