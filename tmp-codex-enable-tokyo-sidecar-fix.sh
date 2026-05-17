#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
PANEL=root@10.0.10.196
CLIENT=root@114.80.33.50
cat >/tmp/codex_enable_tokyo_sidecar_fix.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query("SELECT id, agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
if(!$row){fwrite(STDERR,"missing sidecar 5\n"); exit(2);} 
$cfg=json_decode((string)($row['agent_config_json'] ?? ''), true); if(!is_array($cfg)) $cfg=[];
$cfg['managed_iperf3_ports']=[5201];
$cfg['disable_auto_update']=true;
$cfg['sidecar_agent_update_enabled']=true;
$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=:t, agent_update_requested_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=5')->execute([
  'j'=>json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
  't'=>'tokyo-sidecar-iperf3-'.time(),
]);
echo json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_enable_tokyo_sidecar_fix.php "$PANEL:/tmp/codex_enable_tokyo_sidecar_fix.php" >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_enable_tokyo_sidecar_fix.php'
# Trigger client refresh/reapply so new token target and runtime reach forward 47.
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" "$CLIENT" 'set -euo pipefail
systemctl restart leikwan-client-agent.service
sleep 95
systemctl is-active leikwan-client-agent.service leikwan-client-47.service || true
python3 - <<"PY"
import json, pathlib, re, base64
print("agent_version:")
text=pathlib.Path("/usr/local/bin/leikwan-client-agent.py").read_text()
m=re.search(r"AGENT_VERSION\s*=\s*\"([^\"]+)\"", text); print(m.group(1) if m else "?")
print("start script token payload:")
s=pathlib.Path("/usr/local/bin/leikwan-client-start-47.sh").read_text()
mt=re.search(r"--session-token '\''([^'\'']+)\''", s) or re.search(r"--session-token ([A-Za-z0-9._-]+)", s)
if mt:
    tok=mt.group(1); payload=json.loads(base64.urlsafe_b64decode(tok.split(".")[0]+"==")); print(json.dumps(payload, indent=2))
print("script wstunnel lines:")
for line in s.splitlines():
    if "wstunnel client" in line or "leikwan-bond client" in line: print(line[:500])
PY
journalctl -u leikwan-client-agent.service --since "-2 min" --no-pager | grep -E "applying forward 47|applied forward 47|selected self-leg|desired|update" | tail -80 || true
journalctl -u leikwan-client-47.service --since "-2 min" --no-pager | grep -E "Invalid status code|established|recv_reset|failed to connect upstream|no bond legs" | tail -80 || true
'
