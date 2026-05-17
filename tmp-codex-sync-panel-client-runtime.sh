#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
export SSHPASS='Frank1212.'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
PANEL=root@10.0.10.196
PANEL_ROOT=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py
python3 - <<'PY'
import json, pathlib, hashlib, re
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))
for key, rel in [('client_agent','client-agent/client_agent.py'),('install_forward_runtime','client-agent/install_forward_runtime.py'),('mptcp_client_runtime','client-agent/leikwan_mptcp_client.py')]:
    p=pathlib.Path(rel)
    entry=versions[key]
    actual=hashlib.sha256(p.read_bytes()).hexdigest()
    assert entry['sha256']==actual, (key, entry['sha256'], actual)
    assert int(entry['size_bytes'])==p.stat().st_size, (key, entry['size_bytes'], p.stat().st_size)
print('local manifest ok')
PY
sshpass -e scp "${SSH_OPTS[@]}" \
  master/src/Support/ProvisioningService.php \
  client-agent/client_agent.py \
  client-agent/install_forward_runtime.py \
  artifacts/versions.json \
  master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php \
  "$PANEL:/tmp/" >/dev/null
sshpass -e ssh "${SSH_OPTS[@]}" "$PANEL" "set -e; cd '$PANEL_ROOT'; \
  install -m 0644 /tmp/ProvisioningService.php master/src/Support/ProvisioningService.php; \
  install -m 0644 /tmp/client_agent.py client-agent/client_agent.py; \
  install -m 0755 /tmp/client_agent.py artifacts/client-agent.py; \
  install -m 0644 /tmp/install_forward_runtime.py client-agent/install_forward_runtime.py; \
  install -m 0755 /tmp/install_forward_runtime.py artifacts/install-forward-runtime.py; \
  install -m 0644 /tmp/versions.json artifacts/versions.json; \
  install -m 0644 /tmp/provisioning_mptcp_preferred_bond_payload_regression_test.php master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php; \
  php -l master/src/Support/ProvisioningService.php; \
  php master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php; \
  php master/tests/provisioning_self_leg_payload_regression_test.php; \
  php master/tests/provisioning_self_leg_target_contract_regression_test.php; \
  python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py artifacts/client-agent.py artifacts/install-forward-runtime.py; \
  python3 - <<'PY'\''
import json, pathlib, hashlib, re
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))
for key, rel in [('client_agent','client-agent/client_agent.py'),('install_forward_runtime','client-agent/install_forward_runtime.py')]:
    p=pathlib.Path(rel)
    print(key, versions[key]['version'], versions[key]['sha256'], hashlib.sha256(p.read_bytes()).hexdigest(), p.stat().st_size)
PY"
