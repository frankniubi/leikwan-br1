<?php
declare(strict_types=1);

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Support\ForwardLineApiContract;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $native = ForwardLineApiContract::normalizeLegacyCreate([
        'forward_route_id' => 42,
        'native_outbound' => '1',
        'transport_mode' => 'tcp+udp',
    ]);
    if (($native['native_outbound'] ?? false) !== true
        || array_key_exists('target_host', $native)
        || array_key_exists('target_port', $native)) {
        fail('native legacy create must omit caller target');
    }

    $nativeTenantForm = ForwardLineApiContract::normalizeLegacyCreate([
        'forward_route_id' => 42,
        'listen_port' => null,
        'target_host' => '',
        'target_port' => 0,
        'target_source' => 'manual',
        'transport_mode' => 'tcp+udp',
        'native_outbound' => '1',
    ]);
    if (($nativeTenantForm['native_outbound'] ?? false) !== true
        || array_key_exists('target_host', $nativeTenantForm)
        || array_key_exists('target_port', $nativeTenantForm)) {
        fail('native tenant form create must ignore disabled target placeholders');
    }

    $nativeTenantEdit = ForwardLineApiContract::normalizeLegacyUpdate([
        'listen_port' => null,
        'target_port' => 0,
        'transport_mode' => 'tcp+udp',
        'native_outbound' => '1',
    ]);
    if (($nativeTenantEdit['native_outbound'] ?? false) !== true
        || array_key_exists('target_port', $nativeTenantEdit)) {
        fail('native tenant form edit must ignore disabled target placeholders');
    }

    $structured = ForwardLineApiContract::normalizeCreate([
        'route_id' => 42,
        'listen_port' => null,
        'transport' => 'tcp+udp',
        'native_outbound' => true,
    ]);
    if (($structured['native_outbound'] ?? false) !== true
        || $structured['target_host'] !== null
        || $structured['target_port'] !== null) {
        fail('native structured create must use a server-generated target');
    }

    try {
        ForwardLineApiContract::normalizeLegacyCreate([
            'forward_route_id' => 42,
            'native_outbound' => 'yes',
            'transport_mode' => 'tcp+udp',
        ]);
        fail('invalid native boolean was accepted');
    } catch (\Throwable $exception) {
        if (!str_contains($exception->getMessage(), 'validation_failed')) {
            throw $exception;
        }
    }

    $merged = ForwardLineApiContract::mergePatch([
        'id' => 9,
        'forward_route_id' => 42,
        'listen_port' => 0,
        'target_source' => 'manual',
        'target_host' => '127.0.0.1',
        'target_port' => 23456,
        'transport_mode' => 'tcp+udp',
        'native_outbound_enabled' => 1,
    ], ['native_outbound' => true]);
    if (($merged['native_outbound'] ?? false) !== true || $merged['target_host'] !== '127.0.0.1') {
        fail('native patch must preserve loopback target');
    }

    $presented = ForwardLineApiContract::presentForward([
        'id' => 9,
        'service_id' => 1,
        'forward_route_id' => 42,
        'native_outbound_enabled' => 1,
        'native_direction_key' => 'cn_to_intl',
        'transport_mode' => 'tcp+udp',
        'target_host' => '127.0.0.1',
        'target_port' => 23456,
    ]);
    if (($presented['native_outbound'] ?? false) !== true
        || ($presented['native_direction'] ?? '') !== 'cn_to_intl') {
        fail('native marker was not presented');
    }

    echo "ok\n";
}
