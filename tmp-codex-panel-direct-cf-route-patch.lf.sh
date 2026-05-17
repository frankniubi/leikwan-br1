#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196; PASS='Frank1212.'
cat > /tmp/codex_direct_patch.py <<'PY'
from pathlib import Path
p=Path('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php')
s=p.read_text()
old="$runtimeState = $this->mergeRuntimeState($existingAgentConfig, [\n            'supports_mptcp' => $request->input('supports_mptcp'),\n            'wireguard_public_key' => $request->input('wireguard_public_key'),\n            'wireguard_listen_port' => $request->input('wireguard_listen_port'),\n            'wireguard_fallback_tcp_port' => $request->input('wireguard_fallback_tcp_port'),\n        ]);"
new=old+"\n        $directCfBackendForwardIds = [];\n        $rawDirectCfBackendForwardIds = $runtimeState['cf_direct_backend_forward_ids'] ?? [];\n        if (is_array($rawDirectCfBackendForwardIds)) {\n            foreach ($rawDirectCfBackendForwardIds as $rawForwardId) {\n                $directForwardId = (int) $rawForwardId;\n                if ($directForwardId > 0) {\n                    $directCfBackendForwardIds[$directForwardId] = true;\n                }\n            }\n        }"
if old not in s:
    raise SystemExit('first anchor missing')
s=s.replace(old,new,1)
old2="                    'target' => $target,\n                    'transport_mode' => $transportMode,"
new2="                    'target' => $target,\n                    'transport_mode' => $transportMode,\n                    'backend_mode' => isset($directCfBackendForwardIds[$forwardId]) ? 'direct' : 'wstunnel',"
if old2 not in s:
    raise SystemExit('route anchor missing')
s=s.replace(old2,new2,1)
p.write_text(s)
PY
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 "$PANEL" 'python3 /tmp/codex_direct_patch.py 2>/dev/null || true' || true
sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 /tmp/codex_direct_patch.py "$PANEL:/tmp/codex_direct_patch.py" >/dev/null
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 "$PANEL" 'python3 /tmp/codex_direct_patch.py && php -l /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php && rm -f /tmp/codex_direct_patch.py'