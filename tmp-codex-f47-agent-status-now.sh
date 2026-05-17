#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash -s' <<'RS'
set +e
echo '=== statuses ==='
systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service || true
echo '=== agent status ==='
systemctl status leikwan-client-agent.service --no-pager -l || true
echo '=== f47 status ==='
systemctl status leikwan-client-47.service --no-pager -l || true
echo '=== units cat ==='
systemctl cat leikwan-client-agent.service leikwan-client-47.service --no-pager || true
echo '=== recent logs agent ==='
journalctl -u leikwan-client-agent.service --since '2026-05-16 04:25:00' --no-pager -n 160 || true
echo '=== recent logs f47 ==='
journalctl -u leikwan-client-47.service --since '2026-05-16 04:25:00' --no-pager -n 160 || true
echo '=== versions hashes ==='
python3 - <<'PY'
from pathlib import Path
for p in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py']:
    path=Path(p); print('---',p,path.exists(),path.stat().st_size if path.exists() else '')
    if path.exists():
        txt=path.read_text(errors='ignore')
        for s in ['AGENT_VERSION','def selected_mptcp_self_leg_candidates','direct_legs','target_sidecar_bond_port']:
            if s in txt:
                print('contains',s)
        for line in txt.splitlines()[:60]:
            if 'AGENT_VERSION' in line or 'SELF_LEG_FAST' in line:
                print(line)
PY
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py 2>/dev/null || true
echo '=== selfleg cache ==='
cat /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json 2>/dev/null || true
echo '=== applied 47 ==='
python3 - <<'PY'
import json, pathlib
for p in ['/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/config_pull_state.json','/var/lib/leikwan-client-agent/agent_update_state.json']:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  print(json.dumps(data.get('47',data),indent=2,ensure_ascii=False)[:3000])
 except Exception as e: print(e)
PY
RS
