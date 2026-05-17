#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo '=== kill only codex temp f47 probes ==='
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:291' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:291' 2>/dev/null || true
pkill -f 'iperf3 -c 127\.0\.0\.1 -p 291' 2>/dev/null || true
sleep .5
ps -eo pid,ppid,etimes,cmd | grep -E '127\.0\.0\.1:291|iperf3 -c 127\.0\.0\.1 -p 291' | grep -v grep || true
echo 'agent:'; systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true; systemctl is-active leikwan-client-agent.service 2>/dev/null || true
echo 'f47:'; systemctl is-active leikwan-client-47.service 2>/dev/null || true
REMOTE
