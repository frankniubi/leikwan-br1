set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_query_sidecar5_mgmt.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php'; require 'master/src/Support/ProvisioningService.php';
spl_autoload_register(static function($class){ $p='Leikwan\\Master\\'; if (str_starts_with($class,$p)) { $rel=substr($class,strlen($p)); $path=__DIR__.'/master/src/'.str_replace('\\','/',$rel).'.php'; if (is_file($path)) require_once $path; }});
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$point=$pdo->query("SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,bind_host,token_secret,agent_token,agent_version,agent_config_json,agent_update_request_token FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
print "POINT\n".json_encode($point, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
$rows=$pdo->query("SELECT f.id, f.path_prefix, f.target_host, f.target_port, f.transport_mode, f.bond_remote_host, f.bond_remote_port, f.backend_mode, fc.upstream_realization_mode AS chain_upstream_realization_mode FROM forwards f LEFT JOIN forward_chains fc ON fc.id=f.forward_chain_id INNER JOIN services s ON s.id=f.service_id WHERE f.bond_sidecar_point_id=5 AND f.enabled=1 AND f.backend_mode='cf_mode' AND s.status='active' ORDER BY f.id ASC")->fetchAll(PDO::FETCH_ASSOC);
print "CFROWS\n".json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
$routes=[];
foreach($rows as $row){
 $forwardId=(int)$row['id']; $pathPrefix=(string)$row['path_prefix']; $targetHost=trim((string)($row['bond_remote_host']??'')); $targetPort=(int)($row['bond_remote_port']??0);
 if($targetHost==='' || $targetPort<=0){$targetHost=trim((string)($row['target_host']??'')); $targetPort=(int)($row['target_port']??0);} $target=$targetHost.':'.$targetPort; if($targetPort>0){$target='127.0.0.1:'.$targetPort;}
 $transportMode=(string)($row['transport_mode']??'tcp'); $routes[]=['forward_id'=>$forwardId,'service_name'=>'cf-forward-'.$forwardId,'path_prefix'=>$pathPrefix,'target'=>$target,'transport_mode'=>$transportMode];
 if(str_contains($transportMode,'udp')){$routes[]=['forward_id'=>$forwardId,'service_name'=>'cf-forward-'.$forwardId.'-uot','path_prefix'=>$pathPrefix.'.uot','target'=>'127.0.0.1:8999','transport_mode'=>'tcp'];}
}
print "ROUTES\n".json_encode($routes, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_sidecar5_mgmt.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_query_sidecar5_mgmt.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_query_sidecar5_mgmt.php && rm -f codex_query_sidecar5_mgmt.php'