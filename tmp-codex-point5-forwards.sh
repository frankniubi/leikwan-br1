set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf '=== all tokyo/cf mode forwards on point 5 ===\n'
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$sql="SELECT id,listen_port,client_mode,backend_mode,transport_mode,target_host,target_port,bond_remote_host,bond_remote_port,path_prefix FROM forwards WHERE bond_sidecar_point_id=5 AND enabled=1 ORDER BY id";
foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";}
'
