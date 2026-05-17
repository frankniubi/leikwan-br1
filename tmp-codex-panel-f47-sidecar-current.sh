#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/panel_f47_sidecar_query.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c=new Config('master/.env');
$pdo=(new Database($c))->pdo();
function dump_rows($title,$rows){ echo "-- $title --\n"; foreach($rows as $row){ foreach($row as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $row[$k]='<redacted>'; } echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }}
foreach(['bond_sidecar_points','sidecar_points','forwards','forward_chains','services','clients','client_device_sessions'] as $t){ try{$cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN); echo "-- columns $t --\n".implode(',', $cols)."\n";}catch(Throwable $e){} }
try{ dump_rows('sidecars', $pdo->query('SELECT * FROM bond_sidecar_points ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "sidecars err ".$e->getMessage()."\n"; }
try{ dump_rows('forward47_join', $pdo->query('SELECT f.*, fc.upstream_realization_mode, s.status service_status, c.name client_name FROM forwards f LEFT JOIN forward_chains fc ON fc.id=f.forward_chain_id LEFT JOIN services s ON s.id=f.service_id LEFT JOIN clients c ON c.id=f.line_client_id WHERE f.id=47')->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "f47 err ".$e->getMessage()."\n"; }
try{ dump_rows('client_sessions_3', $pdo->query('SELECT * FROM client_device_sessions WHERE client_id=3 ORDER BY last_seen_at DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "sessions err ".$e->getMessage()."\n"; }
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/panel_f47_sidecar_query.php root@10.0.10.196:/tmp/panel_f47_sidecar_query.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/panel_f47_sidecar_query.php'