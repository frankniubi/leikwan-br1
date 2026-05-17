#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'

echo '=== publish artifacts + versions to panel ==='
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" \
  "$WORKDIR/artifacts/versions.json" \
  "$WORKDIR/client-agent/client_agent.py" \
  "$WORKDIR/install/install-client-agent.sh" \
  "$WORKDIR/client-agent/install_forward_runtime.py" \
  "$WORKDIR/client-agent/leikwan_mptcp_client.py" \
  root@10.0.10.196:/tmp/

sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; \
  install -m 0644 /tmp/versions.json $PANEL_ROOT/artifacts/versions.json; \
  install -m 0644 /tmp/client_agent.py $PANEL_ROOT/client-agent/client_agent.py; \
  install -m 0755 /tmp/install-client-agent.sh $PANEL_ROOT/install/install-client-agent.sh; \
  install -m 0644 /tmp/install_forward_runtime.py $PANEL_ROOT/client-agent/install_forward_runtime.py; \
  install -m 0644 /tmp/leikwan_mptcp_client.py $PANEL_ROOT/client-agent/leikwan_mptcp_client.py; \
  install -m 0644 /tmp/client_agent.py $PANEL_ROOT/artifacts/client-agent.py; \
  install -m 0755 /tmp/install-client-agent.sh $PANEL_ROOT/artifacts/install-client-agent.sh; \
  install -m 0644 /tmp/install_forward_runtime.py $PANEL_ROOT/artifacts/install-forward-runtime.py; \
  install -m 0644 /tmp/leikwan_mptcp_client.py $PANEL_ROOT/artifacts/leikwan-mptcp-client.py; \
  php -l $PANEL_ROOT/master/src/Support/ProvisioningService.php >/dev/null; \
  sha256sum $PANEL_ROOT/artifacts/client-agent.py $PANEL_ROOT/artifacts/install-client-agent.sh $PANEL_ROOT/artifacts/install-forward-runtime.py $PANEL_ROOT/artifacts/leikwan-mptcp-client.py; \
  sed -n '45,75p' $PANEL_ROOT/artifacts/versions.json; sed -n '142,148p' $PANEL_ROOT/artifacts/versions.json"

echo '=== restart and repull on client ==='
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "set -e; systemctl restart leikwan-client-agent.service; sleep 8; systemctl is-active leikwan-client-agent.service; python3 /usr/local/bin/leikwan-client-agent.py --config /etc/leikwan-client-agent/config.json check --live > /tmp/client-check-live-after-publish.json; cat /tmp/client-check-live-after-publish.json"