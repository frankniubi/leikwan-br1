#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
cat >/tmp/codex_sidecar5_mgmt_payload.php <<'PHP'
<?php
require "master/src/Config.php";
require "master/src/Database/Database.php";
require "master/src/Http/Request.php";
require "master/src/Http/Response.php";
require "master/src/Support/Auth.php";
require "master/src/Support/ProvisioningService.php";
require "master/src/Support/BondLegReputationService.php";
require "master/src/Controllers/BaseController.php";
require "master/src/Controllers/SidecarAgentController.php";
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Controllers\SidecarAgentController;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Http\Request;
$config=new Config('master/.env');
$db=new Database($config);
$row=$db->fetchOne('SELECT * FROM sidecar_points WHERE id = 5 LIMIT 1');
if(!$row){fwrite(STDERR,"missing sidecar 5\n"); exit(1);}
$token=(string)$row['agent_token'];
$headers=['authorization'=>'Bearer '.$token,'content-type'=>'application/json'];
$bodyArr=['status'=>'ok','version'=>(string)($row['agent_version'] ?: '2026.05.13.1'),'supports_mptcp'=>true,'usage_bytes_in'=>0,'usage_bytes_out'=>0];
$body=json_encode($bodyArr, JSON_UNESCAPED_SLASHES);
$request=new Request('POST','/api/sidecar/heartbeat',[],$bodyArr,$headers,$body);
$controller=new SidecarAgentController($config,$db,new ProvisioningService($config,$db));
$response=$controller->heartbeat($request,[]);
$ref=new ReflectionClass($response);
$prop=$ref->getProperty('body');
$prop->setAccessible(true);
echo (string)$prop->getValue($response),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar5_mgmt_payload.php "$PANEL:/tmp/codex_sidecar5_mgmt_payload.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_sidecar5_mgmt_payload.php'
