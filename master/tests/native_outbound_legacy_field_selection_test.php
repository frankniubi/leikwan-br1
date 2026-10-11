<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<string,mixed> $row */
        public function __construct(private array $row)
        {
        }

        /** @param array<string,mixed> $params @return array<string,mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return $this->row;
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)
            || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UltraMaterialService;

    function failLegacyFieldSelection(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertLegacyFieldSelection(bool $condition, string $message): void
    {
        if (!$condition) {
            failLegacyFieldSelection($message);
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-native-legacy-env-');
    if ($envPath === false) {
        failLegacyFieldSelection('failed to create temp env');
    }
    file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");

    $method = new ReflectionMethod(UltraMaterialService::class, 'lineAllowsNativeSocks5');
    $method->setAccessible(true);

    $legacyService = new UltraMaterialService(new Config($envPath), new Database([
        'access_class' => 'native_udp',
        'native_udp_state' => 'verified',
        'native_socks5_state' => 'unknown',
        'is_source_present' => 1,
        'is_enabled' => 1,
    ]));
    assertLegacyFieldSelection(
        $method->invoke($legacyService, 'source-home-171', 447) === true,
        'legacy native_udp verified field was not accepted'
    );

    $explicitService = new UltraMaterialService(new Config($envPath), new Database([
        'access_class' => 'native_udp',
        'native_udp_state' => 'unknown',
        'native_socks5_state' => 'verified',
        'is_source_present' => 1,
        'is_enabled' => 1,
    ]));
    assertLegacyFieldSelection(
        $method->invoke($explicitService, 'source-home-171', 447) === true,
        'explicit native_socks5 verified field was not accepted'
    );

    $failedService = new UltraMaterialService(new Config($envPath), new Database([
        'access_class' => 'native_udp',
        'native_udp_state' => 'verified',
        'native_socks5_state' => 'failed',
        'is_source_present' => 1,
        'is_enabled' => 1,
    ]));
    assertLegacyFieldSelection(
        $method->invoke($failedService, 'source-home-171', 447) === false,
        'explicit native_socks5 failure was incorrectly bypassed by legacy state'
    );

    @unlink($envPath);
    echo "ok\n";
}
