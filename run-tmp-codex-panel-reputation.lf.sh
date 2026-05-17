#!/usr/bin/env bash
set -euo pipefail
PANEL=10.0.10.196; PPASS='Frank1212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1)
sshpass -p "$PPASS" ssh "${SSH[@]}" root@$PANEL 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php' <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach(['bond_leg_reputation_current','bond_leg_reputation_events','service_endpoints','nodes','forward_combinations'] as $t){
 echo "-- $t columns --\n"; try{foreach($pdo->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC) as $c){echo $c['Field'].',';} echo "\n";}catch(Throwable $e){echo 'ERR '.$e->getMessage()."\n"; continue;}
 echo "-- $t rows relevant --\n";
 try{
  $sql='SELECT * FROM `'.$t.'` LIMIT 20';
  if($t==='bond_leg_reputation_current') $sql='SELECT * FROM `'.$t.'` WHERE observer_id=5 OR forward_id=47 OR endpoint_id IN (316,317,318,319,320) ORDER BY updated_at DESC LIMIT 50';
  if($t==='bond_leg_reputation_events') $sql='SELECT * FROM `'.$t.'` WHERE observer_id=5 OR forward_id=47 OR endpoint_id IN (316,317,318,319,320) ORDER BY observed_at DESC LIMIT 30';
  if($t==='service_endpoints') $sql='SELECT * FROM `'.$t.'` WHERE id IN (316,317,318,319,320) OR service_id=2 LIMIT 80';
  if($t==='nodes') $sql='SELECT * FROM `'.$t.'` WHERE id IN (SELECT node_id FROM service_endpoints WHERE id IN (316,317,318,319,320)) LIMIT 20';
  if($t==='forward_combinations') $sql='SELECT * FROM `'.$t.'` WHERE id=1';
  foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
 }catch(Throwable $e){echo 'ERR '.$e->getMessage()."\n";}
}
?>
PHP