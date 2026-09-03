<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
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

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;
    use Leikwan\Master\Support\UltraMode;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-native-policy-');
    if ($envPath === false) {
        fail('failed to create temporary env file');
    }
    file_put_contents($envPath, '');

    try {
        $service = new ProvisioningService(new Config($envPath), new Database());
        $defaults = $service->defaultFeatureFlags();
        if (($defaults['allow_native_outbound'] ?? null) !== false
            || ($defaults['allow_native_outbound_unrestricted'] ?? null) !== false) {
            fail('native outbound flags must default off');
        }

        if (!$service->planAllowsNativeOutbound([
            'plan_feature_flags_json' => json_encode(['allow_native_outbound' => true]),
        ])) {
            fail('native plan flag must allow native outbound');
        }
        if (!$service->planAllowsNativeUnrestrictedOutbound([
            'plan_feature_flags_json' => json_encode([
                'allow_native_outbound' => true,
                'allow_native_outbound_unrestricted' => true,
            ]),
        ])) {
            fail('both plan flags must allow unrestricted native outbound');
        }
        foreach ([
            ['allow_native_outbound_unrestricted' => true],
            ['allow_native_outbound' => false, 'allow_native_outbound_unrestricted' => true],
        ] as $flags) {
            if ($service->planAllowsNativeUnrestrictedOutbound([
                'plan_feature_flags_json' => json_encode($flags),
            ])) {
                fail('unrestricted native outbound must fail closed without both plan flags');
            }
        }

        $restrictedV1 = UltraMode::nativeScopeDigest(7, 'cn_to_intl', 'tcp+udp', 'intl_only');
        $restrictedV2 = UltraMode::nativeScopeDigestV2(7, 'cn_to_intl', 'tcp+udp', 'intl_only', 'restricted');
        $unrestrictedV2 = UltraMode::nativeScopeDigestV2(7, 'cn_to_intl', 'tcp+udp', 'intl_only', 'unrestricted');
        if ($restrictedV1 === $restrictedV2 || $restrictedV2 === $unrestrictedV2) {
            fail('route policy must be part of the v2 digest');
        }
        if (UltraMode::normalizeNativeRoutePolicy('') !== UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED
            || UltraMode::normalizeNativeRoutePolicy('unrestricted') !== UltraMode::NATIVE_ROUTE_POLICY_UNRESTRICTED
            || UltraMode::normalizeNativeRoutePolicy('bad') !== null) {
            fail('native route policy normalization failed');
        }
    } finally {
        @unlink($envPath);
    }

    $migration = file_get_contents(__DIR__ . '/../migrations/20260903_add_native_outbound_policy.sql');
    if (!is_string($migration)
        || !str_contains($migration, 'native_outbound_enabled')
        || !str_contains($migration, 'native_direction_key')
        || !str_contains($migration, 'uniq_forwards_native_direction')) {
        fail('native outbound policy migration is incomplete');
    }

    echo "ok\n";
}
