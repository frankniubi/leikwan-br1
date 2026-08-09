<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, array<string, mixed>> */
        public array $combinations = [];
        /** @var array<int, array<string, mixed>> */
        public array $memberships = [];
        public bool $inTransaction = false;
        public bool $committed = false;
        public bool $republishObservedAfterCommit = false;
        public bool $failMembershipInsert = false;
        private int $nextCombinationId = 42;
        private int $nextMembershipId = 1;
        private int $nextLineId = 13;

        public function transaction(callable $callback): mixed
        {
            if ($this->inTransaction) {
                throw new \RuntimeException('nested transaction');
            }
            $snapshot = [$this->lines, $this->combinations, $this->memberships];
            $this->inTransaction = true;
            try {
                $result = $callback($this);
                $this->inTransaction = false;
                $this->committed = true;
                return $result;
            } catch (\Throwable $throwable) {
                [$this->lines, $this->combinations, $this->memberships] = $snapshot;
                $this->inTransaction = false;
                throw $throwable;
            }
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($sql, 'FROM leigod_lines') && isset($params['id'])) {
                return $this->lines[(int) $params['id']] ?? null;
            }
            if (str_contains($sql, 'FROM leigod_lines') && isset($params['line_id'])) {
                foreach ($this->lines as $line) {
                    if ((int) $line['line_id'] === (int) $params['line_id']) {
                        return $line;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'FROM forward_combinations') && isset($params['id'])) {
                return $this->combinations[(int) $params['id']] ?? null;
            }
            return null;
        }

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($sql, 'FROM forward_combination_leigod_lines')) {
                $combinationId = (int) ($params['forward_combination_id'] ?? $params['id'] ?? 0);
                $rows = [];
                foreach ($this->memberships as $membership) {
                    if ((int) $membership['forward_combination_id'] !== $combinationId) {
                        continue;
                    }
                    $line = $this->lines[(int) $membership['leigod_line_id']] ?? [];
                    $rows[] = $membership + [
                        'line_id' => $line['line_id'] ?? 0,
                        'label' => $line['label'] ?? '',
                        'config_sha256' => $line['config_sha256'] ?? '',
                        'line_is_active' => $line['is_active'] ?? 0,
                    ];
                }
                usort($rows, static fn(array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
                return $rows;
            }
            if (str_contains($sql, 'FROM forwards f') && str_contains($sql, 'forward_combination_id')) {
                $this->republishObservedAfterCommit = $this->committed && !$this->inTransaction;
                return [];
            }
            return [];
        }

        public function insert(string $table, array $row): int
        {
            if ($table === 'leigod_lines') {
                $id = $this->nextLineId++;
                $this->lines[$id] = ['id' => $id] + $row;
                return $id;
            }
            if ($table === 'forward_combinations') {
                $id = $this->nextCombinationId++;
                $this->combinations[$id] = ['id' => $id] + $row;
                return $id;
            }
            if ($table === 'forward_combination_leigod_lines') {
                if ($this->failMembershipInsert) {
                    throw new \RuntimeException('forced membership insert failure');
                }
                if (!$this->inTransaction) {
                    throw new \RuntimeException('membership inserted outside transaction');
                }
                $id = $this->nextMembershipId++;
                $this->memberships[$id] = ['id' => $id] + $row;
                return $id;
            }
            return 1;
        }

        public function execute(string $sql, array $params = []): int
        {
            $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_starts_with($sql, 'DELETE FROM forward_combination_leigod_lines')) {
                $combinationId = (int) ($params['forward_combination_id'] ?? 0);
                $before = count($this->memberships);
                $this->memberships = array_filter(
                    $this->memberships,
                    static fn(array $row): bool => (int) $row['forward_combination_id'] !== $combinationId
                );
                return $before - count($this->memberships);
            }
            if (str_starts_with($sql, 'UPDATE forward_combinations')) {
                $id = (int) ($params['id'] ?? 0);
                if (!isset($this->combinations[$id])) {
                    return 0;
                }
                $this->combinations[$id] = array_replace($this->combinations[$id], $params);
                return 1;
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function failMembership(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertMembershipSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failMembership($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $toml451 = "line = 451\n";
    $toml453 = "line = 453\n";
    $envPath = tempnam(sys_get_temp_dir(), 'lw-membership-env-');
    if ($envPath === false) {
        failMembership('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'DEFAULT_TLS_CERT_REF=default-edge-cert',
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        'LEIGOD_LINE_TOML_453=' . base64_encode($toml453),
        '',
    ]));

    $db = new Database();
    $db->lines = [
        11 => [
            'id' => 11,
            'line_id' => 451,
            'label' => 'Korea',
            'config_sha256' => hash('sha256', $toml451),
            'is_active' => 1,
            'sort_order' => 10,
        ],
        12 => [
            'id' => 12,
            'line_id' => 453,
            'label' => 'Japan',
            'config_sha256' => hash('sha256', $toml453),
            'is_active' => 1,
            'sort_order' => 20,
        ],
    ];
    $service = new ProvisioningService(new Config($envPath), $db);
    $base = [
        'name' => 'flexible-asia',
        'tenant_label' => 'Flexible Asia',
        'node_group_key' => 'shanghai-alive',
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'is_active' => 1,
        'leigod_lines' => [
            ['leigod_line_id' => 12, 'enabled' => 1, 'sort_order' => 20],
            ['leigod_line_id' => 11, 'enabled' => 1, 'sort_order' => 10],
            ['leigod_line_id' => 11, 'enabled' => 1, 'sort_order' => 99],
        ],
    ];

    $saved = $service->saveForwardCombination($base);
    $combinationId = (int) ($saved['id'] ?? 0);
    assertMembershipSame(42, $combinationId, 'combination id');
    assertMembershipSame(451, (int) ($db->combinations[$combinationId]['leigod_line_id'] ?? 0), 'legacy first-line projection');
    $memberships = array_values(array_filter(
        $db->memberships,
        static fn(array $row): bool => (int) $row['forward_combination_id'] === $combinationId
    ));
    usort($memberships, static fn(array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
    assertMembershipSame([11, 12], array_map(static fn(array $row): int => (int) $row['leigod_line_id'], $memberships), 'deduplicated ordered memberships');
    assertMembershipSame(true, $db->republishObservedAfterCommit, 'republish happens after transaction commit');
    assertMembershipSame([451, 453], array_map(static fn(array $row): int => (int) $row['line_id'], $saved['leigod_lines'] ?? []), 'returned combination memberships');

    $combinationBeforeRollback = $db->combinations[$combinationId];
    $membershipsBeforeRollback = $db->memberships;
    $db->committed = false;
    $db->republishObservedAfterCommit = false;
    $db->failMembershipInsert = true;
    try {
        $service->saveForwardCombination(array_replace($base, [
            'id' => $combinationId,
            'tenant_label' => 'Must Roll Back',
            'leigod_lines' => [
                ['leigod_line_id' => 12, 'enabled' => 1, 'sort_order' => 10],
            ],
        ]));
        failMembership('expected forced membership insert failure');
    } catch (\RuntimeException $exception) {
        assertMembershipSame('forced membership insert failure', $exception->getMessage(), 'rollback failure message');
    }
    $db->failMembershipInsert = false;
    assertMembershipSame($combinationBeforeRollback, $db->combinations[$combinationId], 'combination rolls back with membership failure');
    assertMembershipSame($membershipsBeforeRollback, $db->memberships, 'memberships roll back with combination failure');
    assertMembershipSame(false, $db->republishObservedAfterCommit, 'failed transaction does not republish');

    $db->committed = false;
    $db->republishObservedAfterCommit = false;
    $reused = $service->saveForwardCombination(array_replace($base, [
        'name' => 'flexible-asia-reuse',
        'tenant_label' => 'Flexible Asia Reuse',
    ]));
    $reusedCombinationId = (int) ($reused['id'] ?? 0);
    $reusedMemberships = array_values(array_filter(
        $db->memberships,
        static fn(array $row): bool => (int) $row['forward_combination_id'] === $reusedCombinationId
    ));
    usort($reusedMemberships, static fn(array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
    assertMembershipSame([11, 12], array_map(static fn(array $row): int => (int) $row['leigod_line_id'], $reusedMemberships), 'catalog lines can be reused across combinations');

    $db->committed = false;
    $db->republishObservedAfterCommit = false;
    $updated = $service->saveForwardCombination(array_replace($base, [
        'id' => $combinationId,
        'backend_mode' => 'current_mode',
        'leigod_lines' => [],
    ]));
    $remainingOriginalMemberships = array_values(array_filter(
        $db->memberships,
        static fn(array $row): bool => (int) $row['forward_combination_id'] === $combinationId
    ));
    assertMembershipSame([], $remainingOriginalMemberships, 'non-LeiGod update clears memberships');
    assertMembershipSame(null, $db->combinations[$combinationId]['leigod_line_id'] ?? null, 'non-LeiGod clears legacy projection');
    assertMembershipSame([], $updated['leigod_lines'] ?? [], 'returned non-LeiGod memberships empty');
    assertMembershipSame(true, $db->republishObservedAfterCommit, 'update republish happens after transaction commit');

    $legacyPayload = array_replace($base, [
        'name' => 'legacy-scalar-line',
        'tenant_label' => 'Legacy Scalar Line',
        'leigod_line_id' => 9001,
    ]);
    unset($legacyPayload['leigod_lines']);
    $legacySaved = $service->saveForwardCombination($legacyPayload);
    $legacyCatalogRows = array_values(array_filter(
        $db->lines,
        static fn(array $row): bool => (int) ($row['line_id'] ?? 0) === 9001
    ));
    assertMembershipSame(1, count($legacyCatalogRows), 'legacy scalar creates one catalog row');
    assertMembershipSame(0, (int) ($legacyCatalogRows[0]['is_active'] ?? 1), 'legacy scalar catalog row is inactive');
    assertMembershipSame('Legacy line 9001', $legacyCatalogRows[0]['label'] ?? null, 'legacy scalar catalog label');
    assertMembershipSame(9001, (int) ($legacySaved['leigod_line_id'] ?? 0), 'legacy scalar projection remains external line id');
    assertMembershipSame([9001], array_map(static fn(array $row): int => (int) $row['line_id'], $legacySaved['leigod_lines'] ?? []), 'legacy scalar normalizes to one membership');

    @unlink($envPath);
    echo "ok\n";
}
