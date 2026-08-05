<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, int> */
        public array $revisions = [20 => 9];
        /** @var array<string, mixed>|null */
        public ?array $session = null;
        /** @var array<int, string> */
        public array $touches = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM clients')) {
                return (string) ($params['token'] ?? '') === 'agent-token'
                    ? ['id' => 20, 'agent_token' => 'agent-token', 'runtime_platform_scope' => 'linux']
                    : null;
            }
            if (str_contains($sql, 'client_device_sessions')) {
                return $this->session;
            }
            if (str_contains($sql, 'client_config_revisions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                return isset($this->revisions[$clientId]) ? ['revision' => $this->revisions[$clientId]] : null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'UPDATE client_device_sessions')) {
                $this->touches[] = (string) ($params['last_pull_at'] ?? 'watch');
                $this->session = array_replace($this->session ?? [], $params);
                return 1;
            }
            return 0;
        }

        public function insert(string $table, array $row): int
        {
            if ($table === 'client_device_sessions') {
                $this->session = ['id' => 1] + $row;
                $this->touches[] = 'insert';
                return 1;
            }
            return 1;
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
        }
    }
}

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

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    function failWatch(string $message): never
    {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }

    function assertWatchSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failWatch($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function watchRequest(string $platform, int $revision): Request
    {
        return new Request('GET', '/api/client/configs/watch', [
            'revision' => $revision,
            'wait' => 0,
        ], [], [
            'Authorization' => 'Bearer agent-token',
            'X-Device-Id' => 'linux-device-a',
            'X-Device-Platform' => $platform,
        ], '');
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-config-watch-');
    if ($envPath === false) {
        failWatch('unable to create env');
    }
    file_put_contents($envPath, "CLIENT_CONFIG_LONG_POLL_ENABLED=1\n");
    $config = new Config($envPath);
    $db = new Database();
    $controller = new ClientAgentController($config, $db, new ProvisioningService($config, $db));

    $changedResponse = $controller->watchConfigs(watchRequest('linux', 8), []);
    assertWatchSame(200, $changedResponse->status(), 'changed response status');
    $changed = json_decode($changedResponse->body(), true);
    assertWatchSame(['changed' => true, 'revision' => 9], $changed, 'changed response body');
    foreach (['forwards', 'toml', 'session', 'secret'] as $forbidden) {
        if (str_contains(strtolower($changedResponse->body()), $forbidden)) {
            failWatch('watch response leaked ' . $forbidden);
        }
    }

    $timeoutResponse = $controller->watchConfigs(watchRequest('linux', 9), []);
    assertWatchSame(['changed' => false, 'revision' => 9], json_decode($timeoutResponse->body(), true), 'timeout body');

    try {
        $controller->watchConfigs(watchRequest('windows', 9), []);
        failWatch('non-Linux watch was accepted');
    } catch (ApiException $exception) {
        assertWatchSame('unsupported_runtime_platform', $exception->getMessage(), 'non-Linux error');
        assertWatchSame(409, $exception->statusCode(), 'non-Linux status');
    }

    $source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
    foreach ([
        "enforceFromRequest(\$client, \$request, 'watch')",
        "'config_revision'",
        "'\"client-' . (int) \$client['id'] . '-r' . \$revision . '\"'",
        "max(0, min(25",
    ] as $needle) {
        if (!str_contains($source, $needle)) {
            failWatch('ClientAgentController missing ' . $needle);
        }
    }
    $bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
    if (!str_contains($bootstrap, "'/api/client/configs/watch'")) {
        failWatch('watch route not registered');
    }

    @unlink($envPath);
    echo "ok\n";
}
