#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client live relay binaries ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
hostname; date -Is
echo agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null)
for p in /usr/local/bin/leikwan-bond /var/lib/leikwan-client-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-mptcp-relay; do
  [ -e "$p" ] || continue
  echo "--- $p"; ls -l "$p"; sha256sum "$p"; "$p" --version 2>&1 | head -3 || true
  strings "$p" | grep -Ei 'invalid bond frame magic|bond frame magic|hello-ack|bond client TCP transport session established|leg_priority|direct_legs' | head -30 || true
done
echo '-- onegood recent error counts --'
journalctl -u leikwan-client-47-onegood.service --since '-30 min' --no-pager -o cat | python3 - <<'PY'
import sys
s=sys.stdin.read()
for pat in ['invalid bond frame magic','failed to read bond frame header','timed out waiting for bond hello-ack','no bond legs acknowledged','bond client TCP transport session established','entering cooldown','local read failed']:
 print(pat, s.count(pat))
PY
REMOTE

echo '=== slave317 live relay/wstunnel binaries ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
hostname; date -Is
for p in /usr/local/bin/leikwan-bond /var/lib/leikwan-slave-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-mptcp-relay /usr/local/bin/wstunnel /var/lib/leikwan-slave-agent/wstunnel; do
  [ -e "$p" ] || continue
  echo "--- $p"; ls -l "$p"; sha256sum "$p"; "$p" --version 2>&1 | head -3 || true
  strings "$p" | grep -Ei 'invalid bond frame magic|bond frame magic|hello-ack|bond client TCP transport session established|leg_priority|direct_legs|http-upgrade-path-prefix' | head -30 || true
done
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service 2>/dev/null); echo wst_pid=$pid; [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
echo '-- recent wst errors --'
journalctl -u wstunnel-svc-2-317.service --since '-30 min' --no-pager -o cat | python3 - <<'PY'
import sys
s=sys.stdin.read()
for pat in ['Invalid status code: 520','failed to do websocket handshake','Opening TCP connection','Closing local','error','WARN']:
 print(pat, s.count(pat))
PY
REMOTE