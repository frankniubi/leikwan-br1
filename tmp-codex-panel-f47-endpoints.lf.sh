#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_panel_f47_endpoints.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
function dumpRows($label,$rows){ echo "-- $label --\n"; foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k))$r[$k]='<redacted>'; } echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; } }
try{ dumpRows('service_endpoints_f47', $pdo->query("SELECT se.*, n.public_ip,n.name,n.agent_version,n.status AS node_status FROM service_endpoints se LEFT JOIN nodes n ON n.id=se.node_id WHERE se.forward_id=47 ORDER BY se.id")->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo 'se err '.$e->getMessage()."\n"; }
try{ dumpRows('provision_recent_f47', $pdo->query("SELECT id,node_id,action,service_id,forward_id,endpoint_id,status,created_at,updated_at, LEFT(payload_json,1200) payload_json, LEFT(result_json,600) result_json,error_message FROM provision_tasks WHERE forward_id=47 OR endpoint_id IN (316,317,318,319,320,340) ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo 'pt err '.$e->getMessage()."\n"; }
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_f47_endpoints.php root@10.0.10.196:/tmp/codex_panel_f47_endpoints.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_f47_endpoints.php'
