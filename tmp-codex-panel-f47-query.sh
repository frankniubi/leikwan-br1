#!/usr/bin/env bash
set -euo pipefail
PANEL_PASS='Frank1212.'
sshpass -p "$PANEL_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$c=new Config("master/.env"); $pdo=(new Database($c))->pdo();
$cols=$pdo->query("SHOW COLUMNS FROM forwards")->fetchAll(PDO::FETCH_COLUMN); echo "forward_cols=".implode(",",$cols)."\n";
$sql="SELECT f.*,sp.name AS sp_name,sp.sidecar_host AS sp_sidecar_host,sp.bond_listen_port,sp.mptcp_listen_port,sp.cf_listen_port,sp.last_heartbeat_at,sp.agent_version,sp.last_status FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id=47";
$r=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC); echo "f47=".json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$routes=[]; foreach($pdo->query("SELECT f.*, s.status AS svc_status FROM forwards f INNER JOIN services s ON s.id=f.service_id WHERE f.bond_sidecar_point_id=5 AND f.enabled=1 AND f.backend_mode=\"cf_mode\" AND s.status=\"active\" ORDER BY f.id")->fetchAll(PDO::FETCH_ASSOC) as $row){$targetHost=trim((string)($row["bond_remote_host"]??"")); $targetPort=(int)($row["bond_remote_port"]??0); if($targetHost===""||$targetPort<=0){$targetHost=trim((string)($row["target_host"]??""));$targetPort=(int)($row["target_port"]??0);} $target=$targetHost.":".$targetPort; if($targetPort>0){$target="127.0.0.1:".$targetPort;} $routes[]=["id"=>(int)$row["id"],"client_mode"=>$row["client_mode"],"target"=>$target,"bond_remote_port"=>(int)($row["bond_remote_port"]??0),"mptcp_sidecar_port"=>(int)($row["mptcp_sidecar_port"]??0),"target_port"=>(int)($row["target_port"]??0)];}
echo "routes_by_current_controller=".json_encode($routes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
'\''; cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && sha256sum master/src/Controllers/SidecarAgentController.php client-agent/install_forward_runtime.py artifacts/versions.json 2>/dev/null || true'