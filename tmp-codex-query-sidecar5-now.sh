set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$s=$pdo->query("SELECT id,name,agent_version,last_heartbeat_at,last_status,last_error,agent_update_request_token,agent_update_requested_at,agent_config_json FROM sidecar_points WHERE id=5");
echo json_encode($s->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
'
