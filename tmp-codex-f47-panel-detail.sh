#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat >/tmp/codex_f47_panel_detail.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$c=new Config('master/.env'); $pdo=(new Database($c))->pdo();
function cols($pdo,$t){ try{return $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable $e){return [];} }
function dump($title,$rows){ echo "-- $title --\n"; foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $r[$k]='<redacted>'; } echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; } }
foreach(['forwards','forward_combinations','forward_combination_forwards','forward_combination_helper_groups','sidecar_points','client_device_sessions'] as $t){ echo "-- cols $t --\n".implode(',', cols($pdo,$t))."\n"; }
$fid=47;
$stmt=$pdo->prepare('SELECT f.* FROM forwards f WHERE f.id=?'); $stmt->execute([$fid]); dump('forward47',$stmt->fetchAll(PDO::FETCH_ASSOC));
try{ $stmt=$pdo->prepare('SELECT * FROM forward_combination_forwards WHERE forward_id=?'); $stmt->execute([$fid]); dump('combo_links',$stmt->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "combo_links err ".$e->getMessage()."\n"; }
try{ $rows=$pdo->query('SELECT * FROM forward_combinations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); dump('forward_combinations',$rows); }catch(Throwable $e){ echo "combos err ".$e->getMessage()."\n"; }
try{ $rows=$pdo->query('SELECT * FROM forward_combination_helper_groups ORDER BY forward_combination_id, id')->fetchAll(PDO::FETCH_ASSOC); dump('helper_groups',$rows); }catch(Throwable $e){ echo "helper err ".$e->getMessage()."\n"; }
try{ $stmt=$pdo->prepare('SELECT * FROM sidecar_points WHERE id=(SELECT bond_sidecar_point_id FROM forwards WHERE id=?)'); $stmt->execute([$fid]); dump('f47_sidecar',$stmt->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "sidecar err ".$e->getMessage()."\n"; }
try{ $stmt=$pdo->query('SELECT * FROM client_device_sessions WHERE client_id=3 ORDER BY last_seen_at DESC LIMIT 5'); dump('client3_sessions',$stmt->fetchAll(PDO::FETCH_ASSOC)); }catch(Throwable $e){ echo "sess err ".$e->getMessage()."\n"; }
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_f47_panel_detail.php root@10.0.10.196:/tmp/codex_f47_panel_detail.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_f47_panel_detail.php'
