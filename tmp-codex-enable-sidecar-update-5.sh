set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$row=$pdo->query("SELECT agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
$cfg=json_decode((string)($row["agent_config_json"]??""), true); if(!is_array($cfg)) $cfg=[];
$cfg["disable_auto_update"]=false;
$cfg["sidecar_agent_update_enabled"]=true;
$cfg["managed_iperf3_ports"]=[5201];
$token="tokyo-sidecar-agent-202605161-".time();
$st=$pdo->prepare("UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=:t, agent_update_requested_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=5");
$st->execute(["j"=>json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "t"=>$token]);
echo "token=$token\n";
echo json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
'
