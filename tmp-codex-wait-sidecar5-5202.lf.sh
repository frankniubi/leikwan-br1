set -euo pipefail
PANEL_PASS='Frank1212.'
cat >/tmp/codex_sidecar5_hb.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$r=$pdo->query('SELECT last_heartbeat_at,last_status,last_error,agent_config_json FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$c=json_decode($r['agent_config_json'] ?? '', true); if(!is_array($c)) $c=[];
echo json_encode(['hb'=>$r['last_heartbeat_at']??null,'status'=>$r['last_status']??null,'err'=>$r['last_error']??null,'ports'=>$c['managed_iperf3_ports']??null,'disable_auto_update'=>$c['disable_auto_update']??null],JSON_UNESCAPED_SLASHES)."\n";
PHP
sshpass -p "$PANEL_PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_sidecar5_hb.php root@10.0.10.196:/tmp/codex_sidecar5_hb.php >/dev/null
for i in $(seq 1 18); do
  out=$(sshpass -p "$PANEL_PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_sidecar5_hb.php')
  echo "wait$i $out"
  echo "$out" | grep -q '5202' && break
  sleep 5
done