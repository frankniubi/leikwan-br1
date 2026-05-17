#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/codex-forward47-payload.py <<'PY'
import json, pathlib, importlib.util, urllib.request
cfg = json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text(encoding='utf-8'))
req = urllib.request.Request(cfg['master_url'].rstrip('/') + '/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-Debug/1.0'})
with urllib.request.urlopen(req, timeout=30) as resp:
    payload = json.load(resp)
forward = next(item for item in payload['forwards'] if int(item.get('forward_id',0)) == 47)
out = {
    'forward_id': forward.get('forward_id'),
    'mode': forward.get('mode'),
    'runtime_preference': forward.get('runtime_preference'),
    'helper_group_cap': forward.get('helper_group_cap'),
    'desired_latency_ms': forward.get('desired_latency_ms'),
    'candidate_pool': [
        {
            'candidate_kind': item.get('candidate_kind'),
            'endpoint_id': item.get('endpoint_id'),
            'origin_group_key': item.get('origin_group_key'),
            'origin_group_order': item.get('origin_group_order'),
            'runtime_capabilities': item.get('runtime_capabilities'),
            'host': item.get('host'),
            'port': item.get('port'),
            'dial_host': item.get('dial_host'),
            'dial_port': item.get('dial_port'),
            'cf_sni_host': item.get('cf_sni_host'),
            'self_leg_cf_candidates': item.get('self_leg_cf_candidates'),
            'target_sidecar_port': item.get('target_sidecar_port'),
            'target_sidecar_mptcp_port': item.get('target_sidecar_mptcp_port'),
        }
        for item in forward.get('candidate_pool', [])
    ],
}
print(json.dumps(out, ensure_ascii=False, indent=2))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-forward47-payload.py root@114.80.33.50:/tmp/codex-forward47-payload.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex-forward47-payload.py'