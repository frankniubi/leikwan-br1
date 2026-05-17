#!/usr/bin/env bash
set -euo pipefail
ROUTE=07289d1f5ed25399
SNI=nrt1.nbwly.com
EDGES='162.159.39.4 162.159.39.29 162.159.39.78 162.159.39.200 104.16.0.3'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=4 -o ServerAliveCountMax=1)
probe_cmd='set +e; command -v curl >/dev/null || true; echo host=$(hostname) now=$(date -Is); for ip in '$EDGES'; do echo --edge=$ip--; curl -sk --connect-timeout 2 --max-time 5 --resolve '$SNI':2053:$ip https://'$SNI':2053/_leikwan/readyz/'$ROUTE' -w "\ncode=%{http_code} time=%{time_connect}/%{time_starttransfer}/%{time_total} remote=%{remote_ip}\n" -o - 2>&1 | sed -n "1,8p"; done'
echo '=== client readyz ==='
sshpass -p 'Frank20021212.' ssh "${SSH[@]}" root@114.80.33.50 "$probe_cmd" || true
cat > /tmp/codex-sh.tsv <<'EOF'
316|47.116.11.144|@XwDQ6H5mgMXbrGD
317|47.116.104.15|U3I+sVL^%IXyqMX0
318|101.133.142.65|b+z@Qk860a+6UsVW
319|47.116.134.3|RQRUbOkeh7ZpCkDa
320|8.133.170.123|TnJKTGse+JMJFR%t
EOF
while IFS='|' read -r eid host pass; do echo "=== slave $eid $host readyz ==="; sshpass -p "$pass" ssh "${SSH[@]}" root@$host "$probe_cmd" || true; done < /tmp/codex-sh.tsv