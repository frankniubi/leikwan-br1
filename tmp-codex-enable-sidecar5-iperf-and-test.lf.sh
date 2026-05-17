#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PANEL_PASS='Frank1212.'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== set sidecar point 5 managed iperf3 5201 + disable sidecar self-update churn ==='
cat >/tmp/codex_enable_sidecar5_iperf.php <<'PHP'
<?php
declare(strict_types=1);
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query('SELECT id,agent_config_json,agent_version,last_heartbeat_at,last_status,last_error FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
if(!$row){fwrite(STDERR,"sidecar point 5 missing\n"); exit(1);} 
$config=[];
if(is_string($row['agent_config_json']??null) && trim((string)$row['agent_config_json'])!==''){
  $decoded=json_decode((string)$row['agent_config_json'],true);
  if(is_array($decoded)) $config=$decoded;
}
$ports=[];
foreach((array)($config['managed_iperf3_ports']??[]) as $p){ $p=(int)$p; if($p>0 && !in_array($p,$ports,true)) $ports[]=$p; }
if(!in_array(5201,$ports,true)) $ports[] = 5201;
$config['managed_iperf3_ports']=$ports;
$config['disable_auto_update']=true;
$config['sidecar_agent_update_enabled']=false;
$json=json_encode($config, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$st=$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=NULL, agent_update_requested_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=5');
$st->execute(['j'=>$json]);
echo "before=".json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
echo "after_config=$json\n";
PHP
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" /tmp/codex_enable_sidecar5_iperf.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_enable_sidecar5_iperf.php" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_enable_sidecar5_iperf.php && rm -f codex_enable_sidecar5_iperf.php'

echo '=== wait for sidecar heartbeat to consume config ==='
sleep 35
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); $r=$pdo->query("SELECT id,agent_version,last_heartbeat_at,last_status,last_error,agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";'\'' '

echo '=== direct client probe to sidecar target 5201 and forward47 iperf ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  echo date=$(date -Is)
  echo --direct-sidecar5201--
  timeout 8 bash -c "iperf3 -c 185.220.238.143 -p 5201 -b 300M -t 5 --connect-timeout 3000" > /tmp/codex-direct-sidecar5201.out 2>&1
  echo rc=$?; cat /tmp/codex-direct-sidecar5201.out
  echo --restart-f47--
  systemctl restart leikwan-client-47.service
  sleep 3
  journalctl -u leikwan-client-47.service --since "-10 sec" --no-pager -o short-iso | tail -60
  echo --forward47-iperf--
  timeout 12 bash -c "iperf3 -c 127.0.0.1 -p 11035 -b 300M -t 8 --connect-timeout 3000" > /tmp/codex-f47-iperf-300.out 2>&1
  echo rc=$?; cat /tmp/codex-f47-iperf-300.out
  echo --logs--
  journalctl -u leikwan-client-47.service --since "-35 sec" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|failed to establish|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|recv_reset|Connection refused|local_write_failed|WARN|ERROR" | tail -200 || true
  echo --snapshot--
  cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
'
