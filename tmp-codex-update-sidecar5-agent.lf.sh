#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PANEL_PASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== sync sidecar agent/controller/artifact metadata to panel ==='
python3 -m py_compile sidecar-agent/sidecar_agent.py
php -l master/src/Controllers/SidecarAgentController.php >/dev/null
for f in master/src/Controllers/SidecarAgentController.php sidecar-agent/sidecar_agent.py artifacts/versions.json install/sidecar-agent-update.sh; do
  dir=$(dirname "$f")
  sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" "mkdir -p /www/wwwroot/v3.leikwanhost.com/leikwan-v3/'$dir'"
  sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" "$f" "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/$f" >/dev/null
done
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'set -euo pipefail
  cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
  php -l master/src/Controllers/SidecarAgentController.php
  python3 -m py_compile sidecar-agent/sidecar_agent.py
  sha256sum master/src/Controllers/SidecarAgentController.php sidecar-agent/sidecar_agent.py artifacts/versions.json install/sidecar-agent-update.sh
  python3 - <<PY
import json, pathlib, re, hashlib
v=json.loads(pathlib.Path("artifacts/versions.json").read_text())
for key in ["sidecar_agent","bond_sidecar_installer","mptcp_sidecar_runtime"]:
 print(key, v.get(key))
text=pathlib.Path("sidecar-agent/sidecar_agent.py").read_text()
print("declared", re.search(r"AGENT_VERSION\s*=\s*\"([^\"]+)\"", text).group(1))
print("sha", hashlib.sha256(pathlib.Path("sidecar-agent/sidecar_agent.py").read_bytes()).hexdigest())
PY
'

echo '=== request sidecar point 5 agent update to current artifact ==='
cat >/tmp/codex_request_sidecar5_update.php <<'PHP'
<?php
declare(strict_types=1);
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query('SELECT id,agent_version,agent_config_json,last_heartbeat_at,last_status,last_error FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
if(!$row){fwrite(STDERR,"sidecar point 5 missing\n"); exit(1);} 
$config=[];
if(is_string($row['agent_config_json']??null) && trim((string)$row['agent_config_json'])!==''){
  $decoded=json_decode((string)$row['agent_config_json'],true); if(is_array($decoded)) $config=$decoded;
}
$config['managed_iperf3_ports']=[5201];
$config['disable_auto_update']=false;
$config['sidecar_agent_update_enabled']=true;
$token='codex-sidecar5-agent-20260516-'.time();
$st=$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=:t, agent_update_requested_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=5');
$st->execute(['j'=>json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'t'=>$token]);
echo "before=".json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
echo "token=$token\n";
echo "config=".json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
PHP
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" /tmp/codex_request_sidecar5_update.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_request_sidecar5_update.php" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_request_sidecar5_update.php && rm -f codex_request_sidecar5_update.php'

echo '=== poll sidecar 5 heartbeat/update for 120s ==='
for i in $(seq 1 12); do
  echo "--- poll $i ---"
  sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); $r=$pdo->query("SELECT id,agent_version,last_heartbeat_at,last_status,last_error,agent_update_request_token,agent_update_requested_at,agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";'\'' '
  sleep 10
done
