#!/usr/bin/env bash
set -euo pipefail
PANEL=10.0.10.196; PPASS='Frank1212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1)
sshpass -p "$PPASS" ssh "${SSH[@]}" root@$PANEL 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php' <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$cols=[]; foreach($pdo->query('SHOW COLUMNS FROM forwards')->fetchAll(PDO::FETCH_ASSOC) as $c){$cols[]=$c['Field'];}
echo "-- forwards columns --\n".implode(',', $cols)."\n";
$want=['id','service_id','user_id','client_id','client_profile_id','client_device_id','path_prefix','listen_port','target_host','target_port','transport_mode','bond_sidecar_point_id','bond_remote_host','bond_remote_port','backend_mode','client_mode','enabled','forward_combination_id','bond_ingress_mode','runtime_preference'];
$sel=array_values(array_filter($want,fn($x)=>in_array($x,$cols,true)));
$sql='SELECT '.implode(',',array_map(fn($x)=>'f.`'.$x.'`',$sel)).', s.status AS service_status FROM forwards f LEFT JOIN services s ON s.id=f.service_id WHERE f.id=47';
foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){echo "-- f47 --\n".json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
$sql='SELECT '.implode(',',array_map(fn($x)=>'f.`'.$x.'`',$sel)).' FROM forwards f WHERE f.bond_sidecar_point_id=5 ORDER BY f.id';
echo "-- point5 forwards --\n"; foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
echo "-- tables rep/health --\n"; foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $tr){$t=$tr[0]; if(preg_match('/reputation|health|leg|bond|endpoint|runtime|publish|session|candidate/i',$t)) echo $t."\n";}
?>
PHP