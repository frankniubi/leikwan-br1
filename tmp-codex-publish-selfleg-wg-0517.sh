set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
command -v sshpass >/dev/null || { echo 'sshpass missing in WSL' >&2; exit 127; }
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
python3 - <<'PY'
import json, pathlib, hashlib
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))
for key in ['client_agent','install_forward_runtime','mptcp_client_runtime','sidecar_agent']:
    e=versions[key]; p=pathlib.Path(e['path'])
    actual=hashlib.sha256(p.read_bytes()).hexdigest(); size=p.stat().st_size
    assert actual == e['sha256'], (key, e['sha256'], actual)
    assert size == e['size_bytes'], (key, e['size_bytes'], size)
print('local manifest ok')
PY
export SSHPASS="$PPASS"
sshpass -e scp "${SSH_OPTS[@]}" \
  master/src/Support/ProvisioningService.php \
  master/src/Controllers/ClientAgentController.php \
  master/src/Controllers/SidecarAgentController.php \
  master/src/Controllers/ArtifactController.php \
  client-agent/client_agent.py \
  client-agent/install_forward_runtime.py \
  client-agent/leikwan_mptcp_client.py \
  sidecar-agent/sidecar_agent.py \
  artifacts/versions.json \
  master/tests/provisioning_self_leg_payload_regression_test.php \
  master/tests/provisioning_self_leg_target_contract_regression_test.php \
  master/tests/provisioning_self_leg_wg_contract_regression_test.php \
  master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php \
  master/tests/provisioning_mptcp_preferred_bond_slave_payload_regression_test.php \
  master/tests/provisioning_mptcp_slave_port_range_regression_test.php \
  "$PANEL:/tmp/" >/dev/null
sshpass -e ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
install -m 0644 /tmp/ProvisioningService.php master/src/Support/ProvisioningService.php
install -m 0644 /tmp/ClientAgentController.php master/src/Controllers/ClientAgentController.php
install -m 0644 /tmp/SidecarAgentController.php master/src/Controllers/SidecarAgentController.php
install -m 0644 /tmp/ArtifactController.php master/src/Controllers/ArtifactController.php
install -m 0644 /tmp/client_agent.py client-agent/client_agent.py
install -m 0755 /tmp/client_agent.py artifacts/client-agent.py
install -m 0644 /tmp/install_forward_runtime.py client-agent/install_forward_runtime.py
install -m 0755 /tmp/install_forward_runtime.py artifacts/install-forward-runtime.py
install -m 0644 /tmp/leikwan_mptcp_client.py client-agent/leikwan_mptcp_client.py
install -m 0755 /tmp/leikwan_mptcp_client.py artifacts/leikwan-mptcp-client.py
install -m 0644 /tmp/sidecar_agent.py sidecar-agent/sidecar_agent.py
install -m 0755 /tmp/sidecar_agent.py artifacts/sidecar-agent.py
install -m 0644 /tmp/versions.json artifacts/versions.json
install -m 0644 /tmp/provisioning_self_leg_payload_regression_test.php master/tests/provisioning_self_leg_payload_regression_test.php
install -m 0644 /tmp/provisioning_self_leg_target_contract_regression_test.php master/tests/provisioning_self_leg_target_contract_regression_test.php
install -m 0644 /tmp/provisioning_self_leg_wg_contract_regression_test.php master/tests/provisioning_self_leg_wg_contract_regression_test.php
install -m 0644 /tmp/provisioning_mptcp_preferred_bond_payload_regression_test.php master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php
install -m 0644 /tmp/provisioning_mptcp_preferred_bond_slave_payload_regression_test.php master/tests/provisioning_mptcp_preferred_bond_slave_payload_regression_test.php
install -m 0644 /tmp/provisioning_mptcp_slave_port_range_regression_test.php master/tests/provisioning_mptcp_slave_port_range_regression_test.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Controllers/ArtifactController.php
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py sidecar-agent/sidecar_agent.py artifacts/client-agent.py artifacts/install-forward-runtime.py artifacts/leikwan-mptcp-client.py artifacts/sidecar-agent.py
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_self_leg_target_contract_regression_test.php
php master/tests/provisioning_self_leg_wg_contract_regression_test.php
php master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php
php master/tests/provisioning_mptcp_preferred_bond_slave_payload_regression_test.php
php master/tests/provisioning_mptcp_slave_port_range_regression_test.php
python3 - <<'PY'
import json, pathlib, hashlib
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))
for key in ['client_agent','install_forward_runtime','mptcp_client_runtime','sidecar_agent']:
    e=versions[key]; p=pathlib.Path(e['path'])
    actual=hashlib.sha256(p.read_bytes()).hexdigest(); size=p.stat().st_size
    print(key, e['version'], actual == e['sha256'], size == e['size_bytes'], actual, size)
    assert actual == e['sha256'] and size == e['size_bytes']
PY
REMOTE
