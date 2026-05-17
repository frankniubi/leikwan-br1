#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== local 47 files ===";
  ls -l /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh || true;
  echo "=== route cache ===";
  cat /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json 2>/dev/null || true;
  echo;
  echo "=== pull state ===";
  cat /var/lib/leikwan-client-agent/config_pull_state.json 2>/dev/null || true;
  echo;
  echo "=== applied 47 ===";
  python3 - <<"PY"
import json, pathlib
p=pathlib.Path('/var/lib/leikwan-client-agent/applied.json')
print(json.dumps(json.loads(p.read_text()).get('47',{}), ensure_ascii=False, indent=2))
PY
'