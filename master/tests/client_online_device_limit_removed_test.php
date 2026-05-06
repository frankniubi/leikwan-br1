<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $clients = [];

        /** @var array<int, array<string, mixed>> */
        public array $sessions = [];

        public int $nextSessionId = 1;

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1')) {
                foreach ($this->sessions as $row) {
                    if ((int) ($row['client_id'] ?? 0) === (int) ($params['client_id'] ?? 0)
                        && (string) ($row['device_id'] ?? '') === (string) ($params['device_id'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM client_device_sessions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $cutoff = (string) ($params['cutoff'] ?? '');
                $total = 0;
                foreach ($this->sessions as $row) {
                    if ((int) ($row['client_id'] ?? 0) !== $clientId) {
                        continue;
                    }
                    if ((string) ($row['last_seen_at'] ?? '') < $cutoff) {
                        continue;
                    }
                    $total++;
                }
                return ['total' => $total];
            }

            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT * FROM client_device_sessions WHERE client_id = :client_id')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                return array_values(array_filter(
                    $this->sessions,
                    static fn(array $row): bool => (int) ($row['client_id'] ?? 0) === $clientId
                ));
            }
            return [];
        }

        public function insert(string $table, array $data): int
        {
            if ($table === 'client_device_sessions') {
                $id = $this->nextSessionId++;
                $this->sessions[$id] = ['id' => $id] + $data;
                return $id;
            }

            if ($table === 'clients') {
                $id = (int) ($data['id'] ?? (count($this->clients) + 1));
                $this->clients[$id] = ['id' => $id] + $data;
                return $id;
            }

            return 1;
        }

        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'UPDATE client_device_sessions SET')) {
                foreach ($this->sessions as $id => $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) ($params['id'] ?? 0)) {
                        continue;
                    }
                    foreach ($params as $key => $value) {
                        if ($key !== 'id') {
                            $this->sessions[$id][$key] = $value;
                        }
                    }
                    return 1;
                }
                return 0;
            }

            return 0;
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
    $db->insert('clients', [
        'id' => 1,
        'agent_token' => 'tok-1',
        'online_device_limit' => 1,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $service = new ClientOnlineSessionService($db);
    $client = $db->clients[1] ?? null;
    if (!is_array($client)) {
        fail('failed to seed client');
    }

    $service->recordAndEnforce($client, 'device-a', [
        'device_name' => 'Windows A',
        'platform' => 'windows-desktop',
        'user_agent' => 'Leikwan-Windows-GUI/test',
    ], 'pull');

    $service->recordAndEnforce($client, 'device-b', [
        'device_name' => 'Android B',
        'platform' => 'android',
        'user_agent' => 'okhttp/test',
    ], 'pull');

    if ($service->activeCountForClient(1) !== 2) {
        fail('expected second device to be accepted and counted');
    }

    $sessions = $service->activeSessionsForClient(1);
    if (count($sessions) !== 2) {
        fail('expected both device sessions to remain queryable');
    }

    $service->recordAndEnforce($client, 'device-a', [
        'device_name' => 'Windows A renamed',
        'platform' => 'windows-desktop',
        'user_agent' => 'Leikwan-Windows-GUI/test',
    ], 'heartbeat');

    $updatedName = null;
    foreach ($db->sessions as $row) {
        if ((string) ($row['device_id'] ?? '') === 'device-a') {
            $updatedName = (string) ($row['device_name'] ?? '');
            break;
        }
    }
    if ($updatedName !== 'Windows A renamed') {
        fail('expected existing device session metadata to update on heartbeat');
    }

    echo "ok\n";
}