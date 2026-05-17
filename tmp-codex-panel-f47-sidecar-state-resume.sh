set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf '=== env exists ===\n'; ls -l master/.env || true
printf '=== forward/sidecar state ===\n'
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$db=(new Database(new Config("master/.env")))->pdo();
function rows($db,$sql,$p=[]){$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);} 
foreach(rows($db,"SELECT id,name,sidecar_host,bind_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,last_heartbeat_at,last_status,agent_version,agent_config_json FROM sidecar_points WHERE id IN (5) OR sidecar_host LIKE ?", ["%185.220.238.143%"] ) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";}
echo "--- forwards 47/59 ---\n";
foreach(rows($db,"SELECT id, service_id, client_mode, backend_mode, transport_mode, listen_port, target_host,target_port,bond_remote_host,bond_remote_port,bond_sidecar_point_id,path_prefix,enabled FROM forwards WHERE id IN (47,59) ORDER BY id") as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";}
'
