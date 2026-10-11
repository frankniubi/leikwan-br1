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

    function failRevision(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $stableForward = [
        'forward_id' => 42,
        'backend_mode' => 'ultra',
        'target' => '1.2.3.4:53',
        'listen_port' => 30522,
        'target_contract' => 'ultra_direct',
        'contract_version' => 2,
        'mode' => 'single',
        'transport' => 'tcp',
        'service_status' => 'active',
        'speed_limit_mbps' => 20,
        'quota_remaining_bytes' => 1024,
    ];

    // Ephemeral grant/line material and metering state must not perturb the
    // forward revision. Quota changes are reported by heartbeat and must not
    // make the runtime reconnect on every traffic update.
    $ephemeralForward = $stableForward;
    $ephemeralForward['_ultra_line_ids'] = [453, 692];
    $ephemeralForward['_ultra_lines'] = [['line_id' => 453, 'relay_ip' => '203.0.113.20']];
    $ephemeralForward['ultra_access'] = ['session_ticket' => 'ticket', 'policy_generation' => str_repeat('a', 64), 'credential' => 'secret'];

    $revStable = ClientAgentController::forwardRevision([$stableForward]);
    $revEphemeral = ClientAgentController::forwardRevision([$ephemeralForward]);
    if ($revStable !== $revEphemeral) {
        failRevision('forward_revision changed when only ephemeral grant material changed');
    }

    $meteredForward = $stableForward;
    $meteredForward['quota_remaining_bytes'] = 512;
    if (ClientAgentController::forwardRevision([$meteredForward]) !== $revStable) {
        failRevision('forward_revision changed when only metered quota changed');
    }

    $changedTarget = $stableForward;
    $changedTarget['target'] = '5.6.7.8:443';
    if (ClientAgentController::forwardRevision([$changedTarget]) === $revStable) {
        failRevision('forward_revision did not change when target changed');
    }

    $changedBackend = $stableForward;
    $changedBackend['backend_mode'] = 'current_mode';
    if (ClientAgentController::forwardRevision([$changedBackend]) === $revStable) {
        failRevision('forward_revision did not change when backend_mode changed');
    }

    $configRevA = ClientAgentController::configRevision($revStable, ['poll_interval' => 30], ['client_agent' => ['version' => '1', 'sha256' => 'aa']]);
    $configRevB = ClientAgentController::configRevision($revStable, ['poll_interval' => 60], ['client_agent' => ['version' => '1', 'sha256' => 'aa']]);
    if ($configRevA === $configRevB) {
        failRevision('config_revision did not change when agent config changed');
    }

    $configRevC = ClientAgentController::configRevision($revStable, ['poll_interval' => 30], ['client_agent' => ['version' => '2', 'sha256' => 'bb']]);
    if ($configRevA === $configRevC) {
        failRevision('config_revision did not change when artifact version changed');
    }

    $source = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
    foreach (['since_revision', 'config_revision', 'forward_revision', 'force_apply', 'next_poll_after_ms', 'Response(304'] as $needle) {
        if (!str_contains($source, $needle)) {
            failRevision('missing revision field/hook: ' . $needle);
        }
    }

    if (!str_contains($source, "is_array(\$snapshot['wstunnel_binary'] ?? null)")) {
        failRevision('pull config response must tolerate a missing wstunnel artifact snapshot');
    }

    echo "ok\n";
}
