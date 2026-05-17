set -euo pipefail
cat >/tmp/codex_panel_f47_endpoints2.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
function dump($label,$rows){ echo "-- $label --\n"; foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $r[$k]='<redacted>'; } echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }}
try{$rows=$pdo->query("SELECT se.*,n.name,n.region,n.public_ip,n.status,n.agent_version,n.last_heartbeat_at,n.agent_config_json FROM service_endpoints se LEFT JOIN nodes n ON n.id=se.node_id WHERE se.forward_id=47 ORDER BY se.id")->fetchAll(PDO::FETCH_ASSOC); dump('f47 endpoints',$rows);}catch(Throwable $e){echo $e->getMessage()."\n";}
try{$rows=$pdo->query("SELECT id,node_id,action,service_id,forward_id,endpoint_id,status,LEFT(payload_json,1600) payload_json, LEFT(result_json,800) result_json,error_message,updated_at FROM provision_tasks WHERE forward_id=47 ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC); dump('tasks f47',$rows);}catch(Throwable $e){echo $e->getMessage()."\n";}
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_f47_endpoints2.php root@10.0.10.196:/tmp/codex_panel_f47_endpoints2.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_f47_endpoints2.php'