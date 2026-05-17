set -euo pipefail
PANEL_PASS='Frank1212.'
cat >/tmp/codex_patch_sidecar5_iperf5202.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query('SELECT id, agent_config_json, last_heartbeat_at FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$cfg=json_decode((string)($row['agent_config_json'] ?? ''), true); if(!is_array($cfg)) $cfg=[];
$ports=$cfg['managed_iperf3_ports'] ?? []; if(!is_array($ports)) $ports=[];
foreach([5201,5202] as $p){ if(!in_array($p,$ports,true)) $ports[]=$p; }
$cfg['managed_iperf3_ports']=array_values($ports);
$cfg['disable_auto_update']=true;
$next=json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$stmt=$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, updated_at=UTC_TIMESTAMP() WHERE id=5');
$stmt->execute(['j'=>$next]);
echo json_encode(['ok'=>true,'before_heartbeat'=>$row['last_heartbeat_at'] ?? null,'ports'=>$cfg['managed_iperf3_ports']], JSON_UNESCAPED_SLASHES)."\n";
PHP
sshpass -p "$PANEL_PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_patch_sidecar5_iperf5202.php root@10.0.10.196:/tmp/codex_patch_sidecar5_iperf5202.php >/dev/null
sshpass -p "$PANEL_PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_patch_sidecar5_iperf5202.php'
# wait for heartbeat to apply config
for i in $(seq 1 18); do
  out=$(sshpass -p "$PANEL_PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; $pdo=(new Leikwan\Master\Database\Database(new Leikwan\Master\Config("master/.env")))->pdo(); $r=$pdo->query("SELECT last_heartbeat_at,last_status,last_error,agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); $c=json_decode($r["agent_config_json"],true); echo json_encode(["hb"=>$r["last_heartbeat_at"],"status"=>$r["last_status"],"err"=>$r["last_error"],"ports"=>$c["managed_iperf3_ports"]??null],JSON_UNESCAPED_SLASHES)."\n";'\'')
  echo "wait$i $out"
  echo "$out" | grep -q '5202' && break
  sleep 5
done