set -euo pipefail
sleep 310
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$row=$pdo->query("SELECT id,name,agent_version,last_heartbeat_at,last_status,last_error,agent_update_request_token,agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
'
