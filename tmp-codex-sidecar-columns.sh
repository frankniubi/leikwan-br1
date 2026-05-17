set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$cols=$pdo->query("SHOW COLUMNS FROM sidecar_points")->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c){ echo $c["Field"],"\n"; }
'
