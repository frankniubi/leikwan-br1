#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
cat > /tmp/codex-bond-selfleg-test.sh <<'RS'
#!/usr/bin/env bash
set +e
j=/etc/leikwan/mptcp-forward-47.json
EDGE=$(python3 - <<'PY'
import json
j=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
print((j.get('direct_legs') or [{}])[0].get('host') or '162.159.39.8')
PY
)
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
LP=43147
LISTEN=11147
TOKEN=$(python3 - <<'PY'
import json
print(json.load(open('/etc/leikwan/mptcp-forward-47.json')).get('tcp_session_token',''))
PY
)
BOND=/usr/local/bin/leikwan-bond
WST=/usr/local/bin/wstunnel
pkill -f "tcp://127.0.0.1:${LP}:127.0.0.1:8899" 2>/dev/null || true
pkill -f "--listen 127.0.0.1:${LISTEN}" 2>/dev/null || true
rm -f /tmp/bond-selfleg-wst.log /tmp/bond-selfleg-bond.log
$WST client -L tcp://127.0.0.1:${LP}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${EDGE}:2053 >/tmp/bond-selfleg-wst.log 2>&1 &
wpid=$!
for i in $(seq 1 60); do ss -ltn "sport = :$LP" | grep -q ":$LP" && break; sleep 0.1; done
ss -ltnp | grep -E ":(${LP}|${LISTEN})" || true
$BOND client --protocol tcp --listen 127.0.0.1:${LISTEN} --legs "tcp://127.0.0.1:${LP}?priority=1" --session-token "$TOKEN" --chunk-size 65536 --max-window-bytes 8388608 --retransmit-ms 700 --connect-timeout-ms 3000 --join-timeout-ms 400 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --interactive-rtt-slack-ms 30 --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 20 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 1800000 >/tmp/bond-selfleg-bond.log 2>&1 &
bpid=$!
for i in $(seq 1 60); do ss -ltn "sport = :$LISTEN" | grep -q ":$LISTEN" && break; sleep 0.1; done
echo ===listeners===
ss -ltnp | grep -E ":(${LP}|${LISTEN})" || true
echo ===iperf-bond-selfleg===
timeout 20 iperf3 -c 127.0.0.1 -p ${LISTEN} -R -b 300M -t 8 --json
printf 'iperf_rc=%s\n' "$?"
sleep 1
echo ===wstlog===; tail -n 120 /tmp/bond-selfleg-wst.log
echo ===bondlog===; tail -n 160 /tmp/bond-selfleg-bond.log
kill $bpid $wpid 2>/dev/null || true
RS
chmod +x /tmp/codex-bond-selfleg-test.sh
sshpass -p "$PASS_CLIENT" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex-bond-selfleg-test.sh root@114.80.33.50:/tmp/codex-bond-selfleg-test.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 /tmp/codex-bond-selfleg-test.sh