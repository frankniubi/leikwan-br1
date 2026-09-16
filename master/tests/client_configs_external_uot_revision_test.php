<?php
declare(strict_types=1);

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Controllers\ClientAgentController;

    function failUoTVision(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    // Enabling, disabling or retuning the sidecar changes the Ultra runtime the
    // client must run, so it has to move the revision the client polls on.
    $nativeUdpForward = [
        'forward_id' => 170,
        'backend_mode' => 'ultra',
        'target' => '1.2.3.4:53',
        'listen_port' => 10001,
        'target_contract' => 'ultra_direct',
        'contract_version' => 2,
        'mode' => 'single',
        'transport' => 'udp',
        'service_status' => 'active',
        'speed_limit_mbps' => 20,
        'forward_combination_external_uot_enabled' => 0,
        'forward_combination_external_uot_revision' => '',
    ];

    $uotForward = $nativeUdpForward;
    $uotForward['forward_combination_external_uot_enabled'] = 1;
    $uotForward['forward_combination_external_uot_revision'] = str_repeat('1', 64);

    $revNative = ClientAgentController::forwardRevision([$nativeUdpForward]);
    $revUot = ClientAgentController::forwardRevision([$uotForward]);
    if ($revUot === $revNative) {
        failUoTVision('forward_revision did not change when the external UoT sidecar was enabled');
    }

    $retuned = $uotForward;
    $retuned['forward_combination_external_uot_revision'] = str_repeat('2', 64);
    if (ClientAgentController::forwardRevision([$retuned]) === $revUot) {
        failUoTVision('forward_revision did not change when the external UoT contract was retuned');
    }

    $disabledAgain = $uotForward;
    $disabledAgain['forward_combination_external_uot_enabled'] = 0;
    $disabledAgain['forward_combination_external_uot_revision'] = '';
    if (ClientAgentController::forwardRevision([$disabledAgain]) !== $revNative) {
        failUoTVision('disabling the external UoT sidecar did not return the revision to the native-UDP value');
    }

    // The raw sidecar secret is not part of the revision projection: only the
    // published digest is. A stray secret in the config payload would both leak
    // it and make the revision depend on material the client must not hash.
    $withRawSecret = $uotForward;
    $withRawSecret['forward_combination_external_uot_token'] = 'not-a-projection-input';
    $withRawSecret['forward_combination_external_uot_sidecar_target'] = '203.0.113.77:7443';
    if (ClientAgentController::forwardRevision([$withRawSecret]) !== $revUot) {
        failUoTVision('forward_revision depended on raw sidecar material instead of the published digest');
    }

    $controllerSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
    if (!str_contains($controllerSource, "'external_uot_revision' => (string) (\$forward['forward_combination_external_uot_revision'] ?? '')")) {
        failUoTVision('ClientAgentController no longer projects the published external UoT revision');
    }

    $provisioningSource = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
    if (!str_contains($provisioningSource, "'forward_combination_external_uot_revision' => \$externalUoTRevision")) {
        failUoTVision('ProvisioningService no longer publishes the external UoT revision digest');
    }
    // The digest is published, the secret is not: the ordinary client config
    // must keep carrying only the enable flag plus the digest.
    $enabledOffset = strpos($provisioningSource, "'forward_combination_external_uot_enabled' =>");
    if ($enabledOffset === false) {
        failUoTVision('ProvisioningService no longer publishes the external UoT enable flag');
    }
    $publishedBlock = substr($provisioningSource, (int) $enabledOffset, 220);
    if (str_contains($publishedBlock, 'external_uot_token') || str_contains($publishedBlock, 'external_uot_sidecar_target')) {
        failUoTVision('external UoT secret material leaked into the ordinary client config payload');
    }

    echo "ok\n";
}
