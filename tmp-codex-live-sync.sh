#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
LOCAL_AGENT="$WORKDIR/client-agent/client_agent.py"
LOCAL_PANEL="$WORKDIR/master/src/Support/ProvisioningService.php"

echo '=== sync files ==='
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" "$LOCAL_AGENT" root@114.80.33.50:/usr/local/bin/leikwan-client-agent.py
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" "$LOCAL_PANEL" root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php

echo '=== verify hashes and lint ==='
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'sha256sum /usr/local/bin/leikwan-client-agent.py; python3 -m py_compile /usr/local/bin/leikwan-client-agent.py'
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'sha256sum /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php; php -l /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php'

echo '=== client live pull check ==='
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /usr/local/bin/leikwan-client-agent.py --config /etc/leikwan-client-agent/config.json check --live > /tmp/client-check-live.json && cat /tmp/client-check-live.json'

echo '=== staged helpers ==='
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'ls -l /tmp/live_pull_selfleg.py /tmp/probe_only47.py /tmp/final_probe_apply47.py 2>/dev/null || true'