<?php
declare(strict_types=1);

// Regression: user 91 (2026-11-12) had two services forwarding to the same
// target ports. The client had no port range, so every automatic listen port
// was the target port and the forwards of both services shared it. A router
// could bind only one forward per port; the others never came up.

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath = '') {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function getInt(string $key, int $default = 0): int { return $default; }
        public function getBool(string $key, bool $default = false): bool { return $default; }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var list<array<string, mixed>> */
        public array $forwards = [];
        public int $clientLocks = 0;

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (!str_contains($sql, 'FROM clients WHERE id = :id LIMIT 1 FOR UPDATE')) {
                throw new \RuntimeException('unexpected query: ' . $sql);
            }
            $this->clientLocks++;
            return ['id' => (int) $params['id']];
        }

        /** @param array<string, mixed> $params @return list<array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'AND listen_port = :listen_port')) {
                return array_values(array_filter(
                    $this->forwards,
                    static fn(array $row): bool => (int) $row['line_client_id'] === (int) $params['client_id']
                        && (int) $row['listen_port'] === (int) $params['listen_port']
                        && (int) $row['enabled'] === 1
                        && (int) ($row['native_outbound_enabled'] ?? 0) === 0
                        && (int) $row['id'] !== (int) $params['exclude_id']
                ));
            }
            if (!str_contains($sql, 'FROM forwards WHERE line_client_id = :client_id')) {
                throw new \RuntimeException('unexpected query: ' . $sql);
            }
            $exclude = (int) ($params['exclude_id'] ?? 0);
            return array_values(array_filter(
                $this->forwards,
                static fn(array $row): bool => (int) $row['line_client_id'] === (int) $params['client_id']
                    && (int) $row['enabled'] === 1
                    && (int) $row['id'] !== $exclude
            ));
        }
    }
}

namespace {
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    require_once __DIR__ . '/../src/Support/ApiException.php';
    require_once __DIR__ . '/../src/Support/ProvisioningService.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $db = new Database();
    $db->forwards = [
        ['id' => 391, 'line_client_id' => 68, 'enabled' => 1, 'listen_port' => 51775],
        ['id' => 449, 'line_client_id' => 68, 'enabled' => 1, 'listen_port' => 51776],
        ['id' => 383, 'line_client_id' => 68, 'enabled' => 0, 'listen_port' => 443],
        ['id' => 500, 'line_client_id' => 99, 'enabled' => 1, 'listen_port' => 22009],
    ];

    $reflection = new ReflectionClass(ProvisioningService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $dbProperty = $reflection->getProperty('db');
    $dbProperty->setAccessible(true);
    $dbProperty->setValue($service, $db);
    $method = $reflection->getMethod('freeClientListenPort');
    $method->setAccessible(true);
    $pick = static fn(?array $client, ?int $exclude, int $port): int => $method->invoke($service, $client, $exclude, $port);

    $client = ['id' => 68, 'port_range_start' => null, 'port_range_end' => null];

    // A port the panel chooses itself moves to a free one.
    check($pick($client, null, 51775) === 51777, 'a taken target port moves to the next free port');
    check($pick($client, null, 22009) === 22009, 'another client\'s forward does not take the port');
    check($pick($client, null, 443) === 443, 'a disabled forward does not hold its port');
    check($pick($client, 391, 51775) === 51775, 'a forward keeps its own port on update');
    check($pick($client, 449, 51775) === 51776, 'an updated forward skips only ports held by others');
    check($pick(null, null, 51775) === 51775, 'without a client the port is unchanged');
    check($pick($client, null, 0) === 0, 'no port stays no port');

    // A port the user asks for is refused when it is taken, never moved.
    $db->forwards = [
        ['id' => 391, 'line_client_id' => 68, 'enabled' => 1, 'listen_port' => 51775, 'transport_mode' => 'tcp+udp'],
        ['id' => 392, 'line_client_id' => 68, 'enabled' => 1, 'listen_port' => 7000, 'transport_mode' => 'tcp'],
        ['id' => 393, 'line_client_id' => 68, 'enabled' => 0, 'listen_port' => 7100, 'transport_mode' => 'tcp'],
        ['id' => 394, 'line_client_id' => 68, 'enabled' => 1, 'listen_port' => 7200, 'transport_mode' => 'tcp+udp', 'native_outbound_enabled' => 1],
        ['id' => 500, 'line_client_id' => 99, 'enabled' => 1, 'listen_port' => 22009, 'transport_mode' => 'tcp+udp'],
    ];
    $assert = $reflection->getMethod('assertClientListenPortFree');
    $assert->setAccessible(true);
    $conflict = static function (int $clientId, ?int $exclude, int $port, string $transport) use ($assert, $service): ?ApiException {
        try {
            $assert->invoke($service, $clientId, $exclude, $port, $transport);
            return null;
        } catch (ApiException $exception) {
            return $exception;
        }
    };

    $refused = $conflict(68, null, 51775, 'tcp');
    check($refused !== null && $refused->getMessage() === 'listen_port_conflict', 'a taken listen port is refused');
    check(($refused->details()['fields'] ?? null) === ['listen_port' => 'in_use'], 'the refusal names the listen_port field');
    check(($refused->details()['conflicting_forward_id'] ?? 0) === 391, 'the refusal names the forward that holds the port');
    check($db->clientLocks > 0, 'the client row is locked before the port is claimed');
    check($conflict(68, 391, 51775, 'tcp+udp') === null, 'a forward does not conflict with itself');
    check($conflict(68, null, 7000, 'udp') === null, 'udp may share a port with a tcp-only forward');
    check($conflict(68, null, 7000, 'tcp+udp') !== null, 'tcp+udp conflicts with a tcp-only forward');
    check($conflict(68, null, 7100, 'tcp') === null, 'a disabled forward does not hold its port');
    check($conflict(68, null, 7200, 'tcp') === null, 'a native outbound forward binds no listen port');
    check($conflict(68, null, 22009, 'tcp') === null, 'another client may use the same port');
    check($conflict(0, null, 51775, 'tcp') === null, 'a forward without a line client is not checked');

    $source = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
    check(
        preg_match('/\$listenPort = \$autoPort > 0 \? \$autoPort : [^;]+;\s+\$listenPort = \$this->freeClientListenPort\(\$defaultClient, \$forwardId, \$listenPort\);/', $source) === 1,
        'saveForward routes the automatic listen port through freeClientListenPort'
    );
    check(substr_count($source, '$this->assertClientListenPortFree(') === 3, 'create/update, edit and start all check the listen port');

    foreach (['TenantController', 'ForwardLineApiController', 'ServiceController'] as $controller) {
        check(
            str_contains((string) file_get_contents(__DIR__ . '/../src/Controllers/' . $controller . '.php'), "'listen_port_conflict'"),
            $controller . ' maps listen_port_conflict'
        );
    }

    echo "PASS\n";
}
