#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'

echo '=== publish corrected renderer ==='
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" "$WORKDIR/client-agent/install_forward_runtime.py" "$WORKDIR/client-agent/client_agent.py" "$WORKDIR/artifacts/versions.json" root@10.0.10.196:/tmp/
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" "$WORKDIR/client-agent/install_forward_runtime.py" "$WORKDIR/client-agent/client_agent.py" root@114.80.33.50:/tmp/
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; install -m 0644 /tmp/install_forward_runtime.py $PANEL_ROOT/client-agent/install_forward_runtime.py; install -m 0644 /tmp/install_forward_runtime.py $PANEL_ROOT/artifacts/install-forward-runtime.py; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/client-agent/client_agent.py; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/artifacts/client-agent.py; install -m 0644 /tmp/versions.json $PANEL_ROOT/artifacts/versions.json; sha256sum $PANEL_ROOT/artifacts/install-forward-runtime.py $PANEL_ROOT/artifacts/client-agent.py; sed -n '45,50p' $PANEL_ROOT/artifacts/versions.json; sed -n '142,148p' $PANEL_ROOT/artifacts/versions.json"
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "install -m 0644 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py; install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py; python3 -m py_compile /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-client-agent.py; systemctl restart leikwan-client-agent.service; sleep 8; systemctl is-active leikwan-client-agent.service"