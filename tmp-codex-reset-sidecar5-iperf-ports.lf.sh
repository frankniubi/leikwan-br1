#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
patch_ports(){
  local ports_json="$1"
  cat >/tmp/codex_sidecar5_ports.php <<PHP
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\\Master\\Config; use Leikwan\\Master\\Database\\Database;
\$pdo=(new Database(new Config('master/.env')))->pdo();
\$row=\$pdo->query('SELECT agent_config_json,last_heartbeat_at FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
\$cfg=json_decode((string)(\$row['agent_config_json']??''),true); if(!is_array(\$cfg)) \$cfg=[];
\$cfg['managed_iperf3_ports']=$ports_json;
\$cfg['disable_auto_update']=true; \$cfg['sidecar_agent_update_enabled']=false;
\$st=\$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=NULL, agent_update_requested_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=5');
\$st->execute(['j'=>json_encode(\$cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
echo json_encode(['ok'=>true,'before_hb'=>\$row['last_heartbeat_at']??null,'ports'=>\$cfg['managed_iperf3_ports']], JSON_UNESCAPED_SLASHES)."\n";
PHP
  sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar5_ports.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar5_ports.php" >/dev/null
  sshpass -p "$PPASS" ssh -T "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar5_ports.php && rm -f codex_sidecar5_ports.php'
}
show_hb(){
  sshpass -p "$PPASS" ssh -T "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); $r=$pdo->query("SELECT last_heartbeat_at,last_status,last_error,agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); $c=json_decode($r["agent_config_json"],true); echo json_encode(["hb"=>$r["last_heartbeat_at"],"status"=>$r["last_status"],"err"=>$r["last_error"],"ports"=>$c["managed_iperf3_ports"]??null],JSON_UNESCAPED_SLASHES)."\n";'\'' '
}
echo '=== remove managed iperf ports to clear stale servers ==='
patch_ports '[]'
for i in $(seq 1 10); do echo -n "off_wait$i "; show_hb; sleep 6; done
echo '=== re-add managed iperf ports 5201/5202 ==='
patch_ports '[5201,5202]'
for i in $(seq 1 12); do echo -n "on_wait$i "; show_hb; sleep 6; done
echo '=== direct public sidecar port probes from client ==='
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
for p in 5201 5202 22 8899 2053; do
  echo "-- tcp $p --"
  for i in 1 2 3; do timeout 3 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo ok || echo fail; done
done
for p in 5201 5202; do
  echo "-- direct iperf $p --"
  timeout 12 iperf3 -c 185.220.238.143 -p $p -R -b 300M -t 5 --connect-timeout 3000 2>&1 | sed -n '1,80p'
done
REMOTE
