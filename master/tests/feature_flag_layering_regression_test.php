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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-feature-flags-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $planFlags = json_encode([
        'allow_manage_clients' => true,
        'allow_delete_clients' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($planFlags)) {
        fail('failed to encode plan flags');
    }

    $emptyUserFlags = $service->effectiveFeatureFlagsForService([
        'plan_feature_flags_json' => $planFlags,
        'user_feature_flags_json' => '',
    ]);

    if (($emptyUserFlags['allow_manage_clients'] ?? null) !== true) {
        fail('expected empty user feature flags to preserve plan allow_manage_clients');
    }
    if (($emptyUserFlags['allow_delete_clients'] ?? null) !== true) {
        fail('expected empty user feature flags to preserve plan allow_delete_clients');
    }

    $partialUserFlags = json_encode([
        'allow_manage_clients' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($partialUserFlags)) {
        fail('failed to encode partial user flags');
    }

    $effectiveFlags = $service->effectiveFeatureFlagsForService([
        'plan_feature_flags_json' => $planFlags,
        'user_feature_flags_json' => $partialUserFlags,
    ]);

    if (($effectiveFlags['allow_manage_clients'] ?? null) !== false) {
        fail('expected explicit user override to disable allow_manage_clients');
    }
    if (($effectiveFlags['allow_delete_clients'] ?? null) !== true) {
        fail('expected unrelated plan allow_delete_clients flag to survive partial user overrides');
    }

    echo "ok\n";
}
