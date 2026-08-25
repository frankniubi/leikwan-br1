<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $sessions = [];

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM client_device_sessions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $cutoff = (string) ($params['cutoff'] ?? '');
                $total = 0;
                foreach ($this->sessions as $row) {
                    if ((int) ($row['client_id'] ?? 0) === $clientId
                        && (string) ($row['last_seen_at'] ?? '') >= $cutoff) {
                        $total++;
                    }
                }
                return ['total' => $total];
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            return array_values($this->sessions);
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (!str_contains($normalized, 'DELETE FROM client_device_sessions WHERE client_id = :client_id')) {
                return 0;
            }
            $clientId = (int) ($params['client_id'] ?? 0);
            $before = count($this->sessions);
            $this->sessions = array_values(array_filter(
                $this->sessions,
                static fn(array $row): bool => (int) ($row['client_id'] ?? 0) !== $clientId
            ));
            return $before - count($this->sessions);
        }
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

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ClientOnlineSessionService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $db = new Database();
    $now = gmdate('Y-m-d H:i:s');
    $db->sessions = [
        ['id' => 1, 'client_id' => 1, 'device_id' => 'linux-a', 'last_seen_at' => $now],
        ['id' => 2, 'client_id' => 1, 'device_id' => 'windows-b', 'last_seen_at' => $now],
        ['id' => 3, 'client_id' => 2, 'device_id' => 'other', 'last_seen_at' => $now],
    ];

    $service = new ClientOnlineSessionService($db);
    $service->revokeAllForClient(1);

    foreach ($db->sessions as $row) {
        if ((int) ($row['client_id'] ?? 0) === 1) {
            fail('expected every session for the reset client to be removed');
        }
    }
    if ($service->activeCountForClient(1) !== 0) {
        fail('expected revoked client to report zero active devices');
    }
    if (count($db->sessions) !== 1) {
        fail('expected sessions for other clients to remain intact');
    }

    echo "ok\n";
}
