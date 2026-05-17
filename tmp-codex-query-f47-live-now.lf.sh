set -euo pipefail
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_query_f47.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
function scrub($r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $r[$k]='<redacted len='.strlen((string)$v).'>'; } return $r; }
function dump($label,$rows){ echo "-- $label --\n"; foreach($rows as $r){ echo json_encode(scrub($r),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }}
$queries=[
 'forward47'=>"SELECT f.*,fc.name combo_name,fc.runtime_preference,fc.helper_group_cap,fc.self_leg_role,sp.name point_name,sp.sidecar_host,sp.bond_listen_port,sp.mptcp_listen_port,sp.cf_listen_port FROM forwards f LEFT JOIN forward_combinations fc ON fc.id=f.forward_combination_id LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id=47",
 'endpoints'=>"SELECT se.id endpoint_id,se.node_id,se.forward_id,se.listen_port,se.backend_port,se.target_host,se.target_port,se.ingress_protocol,se.backend_protocol,se.status,se.cf_mode_json,n.public_ip,n.name,n.region,n.status node_status FROM service_endpoints se LEFT JOIN nodes n ON n.id=se.node_id WHERE se.forward_id=47 ORDER BY se.id",
 'tasks'=>"SELECT id,node_id,action,service_id,forward_id,endpoint_id,status,LEFT(payload_json,1000) payload_json,LEFT(result_json,500) result_json,error_message,updated_at FROM provision_tasks WHERE forward_id=47 ORDER BY id DESC LIMIT 20",
];
foreach($queries as $label=>$sql){ try{ dump($label,$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "ERR $label ".$e->getMessage()."\n"; } }
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_f47.php "$PANEL:/tmp/codex_query_f47.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_query_f47.php; rm -f /tmp/codex_query_f47.php'
