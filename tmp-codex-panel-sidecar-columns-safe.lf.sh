set -euo pipefail
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
foreach(["sidecar_points","sidecar_nodes","nodes","credential_profiles","server_credential_profiles"] as $t){
  try { echo "--$t columns--\n"; $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC); foreach($cols as $c){ echo $c["Field"]."\n"; } }
  catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }
}
echo "--point5 safe--\n";
$r=$pdo->query("SELECT * FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
foreach($r?:[] as $k=>$v){ if(preg_match("/(pass|key|secret|token|credential)/i",$k)) { echo "$k=<redacted len=".strlen((string)$v).">\n"; } else { echo "$k=$v\n"; }}
'\'''
