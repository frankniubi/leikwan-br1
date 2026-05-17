set -euo pipefail
cat > /tmp/codex_panel_endpoints_all47.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
function dump($label,$rows){ echo "-- $label --\n"; foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $r[$k]='<redacted>'; } echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }}
foreach(['service_endpoints','forward_endpoints','service_endpoint_forward','forward_nodes'] as $t){ try{$rows=$pdo->query("SELECT * FROM `$t` WHERE forward_id=47 OR service_id=2 OR id IN (316,317,318,319,320,340) OR endpoint_id IN (316,317,318,319,320,340) ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); dump($t,$rows);}catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }}
try{$rows=$pdo->query("SELECT se.*,n.public_ip,n.name,n.status,n.agent_token,n.agent_config_json FROM service_endpoints se LEFT JOIN nodes n ON n.id=se.node_id WHERE se.forward_id=47 OR se.id IN (316,317,318,319,320,340) ORDER BY se.id")->fetchAll(PDO::FETCH_ASSOC); dump('joined',$rows);}catch(Throwable $e){ echo 'joined err '.$e->getMessage()."\n"; }
try{$rows=$pdo->query("SELECT id,service_id,forward_id,node_id,listen_port,backend_port,target_host,target_port,ingress_protocol,backend_protocol,cf_mode_json,status,updated_at FROM service_endpoints WHERE service_id=2 ORDER BY id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC); dump('service2 recent',$rows);}catch(Throwable $e){ echo 'svc2 err '.$e->getMessage()."\n"; }
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_endpoints_all47.php root@10.0.10.196:/tmp/codex_panel_endpoints_all47.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_endpoints_all47.php'