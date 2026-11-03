<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    $prefix='Leikwan\\Master\\';
    if(str_starts_with($class,$prefix)) {
        $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($path))require_once $path;
    }
});
use Leikwan\Master\Controllers\ClientAgentController as Controller;
$stable=hash('sha256','stable-fixture');
$forward=['forward_id'=>42,'backend_mode'=>'ultra','service_status'=>'active'];
$pending=Controller::pullDeliveryMetadata($stable,30000,[$forward]);
if($pending['config_revision']===$stable || $pending['next_poll_after_ms']!==5000)
    throw new RuntimeException('missing grant acknowledged stable revision');
if($pending!==Controller::pullDeliveryMetadata($stable,30000,[$forward]))
    throw new RuntimeException('pending revision must be deterministic');
$healthy=$forward+['ultra_access'=>['protocol'=>'leikwan-ultra-policy-v2','session_ticket'=>'fixture-only']];
if(Controller::pullDeliveryMetadata($stable,30000,[$healthy])!==['config_revision'=>$stable,'next_poll_after_ms'=>30000])
    throw new RuntimeException('healthy grant changed stable revision');
foreach([$forward+['native_outbound_enabled'=>true],array_replace($forward,['service_status'=>'suspended']),array_replace($forward,['backend_mode'=>'bond'])] as $excluded) {
    if(Controller::pullDeliveryMetadata($stable,30000,[$excluded])['config_revision']!==$stable)
        throw new RuntimeException('nonordinary grant forced retries');
}
if(Controller::pullDeliveryMetadata($stable,30000,[$healthy,$forward])['config_revision']===$stable)
    throw new RuntimeException('partial grant batch acknowledged stable revision');
echo "pending grant revision: PASS\n";
