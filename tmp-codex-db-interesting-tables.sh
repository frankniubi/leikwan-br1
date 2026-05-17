set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t){
  $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
  $names=array_map(fn($c)=>$c["Field"],$cols);
  $interesting=array_filter($names, fn($n)=>preg_match("/(host|ip|sidecar|ssh|port|user|pass|credential|secret|token|key|name)/i",$n));
  if($interesting){
    echo "-- $t: ".implode(",",$interesting)."\n";
  }
}
'
