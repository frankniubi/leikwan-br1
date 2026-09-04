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

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-native-plan-');
    if ($envPath === false) {
        fail('failed to create temporary env file');
    }
    file_put_contents($envPath, '');

    try {
        $service = new ProvisioningService(new Config($envPath), new Database());
        $defaults = $service->defaultFeatureFlags();
        if (($defaults['allow_native_outbound'] ?? null) !== false) {
            fail('native outbound must default off');
        }

        $planAllows = [
            'plan_feature_flags_json' => json_encode(['allow_native_outbound' => true]),
            'user_feature_flags_json' => json_encode(['allow_native_outbound' => false]),
        ];
        if (!$service->planAllowsNativeOutbound($planAllows)) {
            fail('enabled plan must allow native outbound');
        }

        $userCannotElevate = [
            'plan_feature_flags_json' => json_encode(['allow_native_outbound' => false]),
            'user_feature_flags_json' => json_encode(['allow_native_outbound' => true]),
        ];
        if ($service->planAllowsNativeOutbound($userCannotElevate)) {
            fail('user flags must not elevate native outbound');
        }

        foreach ([[], ['plan_feature_flags_json' => ''], ['plan_feature_flags_json' => '{']] as $serviceRow) {
            if ($service->planAllowsNativeOutbound($serviceRow)) {
                fail('malformed or absent plan flags must fail closed');
            }
        }
    } finally {
        @unlink($envPath);
    }

    $migration = file_get_contents(__DIR__ . '/../migrations/20260901_add_native_socks5_state_and_plan_flag.sql');
    if (!is_string($migration)
        || !str_contains($migration, 'native_socks5_state')
        || !preg_match("/native_socks5_state\\s+VARCHAR\\(16\\)\\s+NOT NULL\\s+DEFAULT\\s+'unknown'/i", $migration)
        || !str_contains($migration, 'allow_native_outbound')) {
        fail('migration must add native_socks5_state and document the plan flag');
    }

    $schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
    if (!is_string($schema)
        || !preg_match("/native_socks5_state\\s+VARCHAR\\(16\\)\\s+NOT NULL\\s+DEFAULT\\s+'unknown'/i", $schema)
        || !preg_match("/native_udp_state\\s+VARCHAR\\(32\\)\\s+NOT NULL\\s+DEFAULT\\s+'unknown'/i", $schema)) {
        fail('schema must preserve native_udp_state and add native_socks5_state defaults');
    }

    echo "ok\n";
}
