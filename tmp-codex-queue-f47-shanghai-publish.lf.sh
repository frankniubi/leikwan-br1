#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_queue_f47_shanghai.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
require 'master/src/Support/ProvisioningService.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;
$c=new Config('master/.env');
$db=new Database($c);
$svc=new ProvisioningService($c,$db);
$payloadRef=new ReflectionMethod($svc,'publishTaskPayloadForForward');
$payloadRef->setAccessible(true);
$queueRef=new ReflectionMethod($svc,'queueTask');
$queueRef->setAccessible(true);
$pdo=$db->pdo();
$rows=$pdo->query("SELECT e.id AS service_endpoint_id, e.node_id, e.public_port AS endpoint_public_port, f.*, n.*
 FROM service_endpoints e
 INNER JOIN forwards f ON f.id=e.forward_id
 INNER JOIN nodes n ON n.id=e.node_id
 WHERE e.forward_id=47 AND e.health_status NOT IN ('deleted','deleting') AND e.node_id IN (12,13,14,15,16)
 ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){
    $endpointId=(int)$row['service_endpoint_id'];
    $nodeId=(int)$row['node_id'];
    $forward=$row;
    $forward['id']=47;
    $node=$row;
    $node['id']=$nodeId;
    $payload=$payloadRef->invoke($svc,$forward,$node,$endpointId);
    $queueRef->invoke($svc,$nodeId,'publish',(int)$row['service_id'],47,$endpointId,$payload);
    echo json_encode(['queued_endpoint'=>$endpointId,'node_id'=>$nodeId,'target'=>$payload['target']??null,'ingress_protocol'=>$payload['ingress_protocol']??null,'backend_protocol'=>$payload['backend_protocol']??null,'publish_bond_runtime'=>$payload['publish_bond_runtime']??null,'has_underlay'=>is_array($payload['mptcp_underlay']??null)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
}
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_queue_f47_shanghai.php root@10.0.10.196:/tmp/codex_queue_f47_shanghai.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_queue_f47_shanghai.php'
