<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\LeigodExecutionLeaseService;

const LEIGOD_EXECUTION_LEASE_SEED_CONFIRMATION = 'SEED-LEIGOD-LEASES';

/**
 * @param array<int, string> $argv
 * @return array{apply:bool,dry_run:bool,help:bool,confirm:?string}
 */
function leigodExecutionLeaseSeedParseArguments(array $argv): array
{
    $apply = false;
    $explicitDryRun = false;
    $help = false;
    $confirm = null;

    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = (string) $argv[$index];
        if ($argument === '--apply') {
            $apply = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $explicitDryRun = true;
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            $help = true;
            continue;
        }
        if ($argument === '--confirm') {
            $index++;
            if ($index >= $count || str_starts_with((string) $argv[$index], '--')) {
                throw new InvalidArgumentException('seed_confirm_value_missing');
            }
            $confirm = (string) $argv[$index];
            continue;
        }
        if (str_starts_with($argument, '--confirm=')) {
            $confirm = substr($argument, strlen('--confirm='));
            continue;
        }
        throw new InvalidArgumentException('seed_unknown_argument');
    }

    if (!$help) {
        if ($apply && $explicitDryRun) {
            throw new InvalidArgumentException('seed_mode_conflict');
        }
        if ($apply && $confirm !== LEIGOD_EXECUTION_LEASE_SEED_CONFIRMATION) {
            throw new InvalidArgumentException('seed_apply_confirmation_required');
        }
        if (!$apply && $confirm !== null) {
            throw new InvalidArgumentException('seed_confirm_requires_apply');
        }
    }

    return [
        'apply' => $apply,
        'dry_run' => !$apply,
        'help' => $help,
        'confirm' => $confirm,
    ];
}

/**
 * @param array<string, mixed> $options
 * @return array{catalog_lines:int,desired_pairs:int,seeded:int,conflicts:int,unchanged:int}
 */
function leigodExecutionLeaseSeedRun(
    array $options,
    mixed $db,
    mixed $leaseService,
    ?callable $clock = null,
): array {
    $clock ??= static fn(): int => time();
    if (!(bool) ($options['apply'] ?? false)) {
        $audit = leigodExecutionLeaseSeedAudit($db, false, (int) $clock());
        return $audit['summary'];
    }

    return $db->transaction(static function () use ($db, $leaseService, $clock): array {
        $audit = leigodExecutionLeaseSeedAudit($db, true, (int) $clock());
        $summary = $audit['summary'];
        if ($summary['conflicts'] > 0) {
            return $summary;
        }

        foreach ($audit['to_seed'] as $pair) {
            $leaseService->acquireForNode($pair['leigod_line_id'], $pair['node_id']);
            $summary['seeded']++;
        }
        return $summary;
    });
}

/**
 * @return array{
 *   summary:array{catalog_lines:int,desired_pairs:int,seeded:int,conflicts:int,unchanged:int},
 *   to_seed:array<int,array{leigod_line_id:int,node_id:int}>
 * }
 */
function leigodExecutionLeaseSeedAudit(mixed $db, bool $forUpdate, int $now): array
{
    $lockSql = $forUpdate ? "\nFOR UPDATE" : '';
    $catalogRows = $db->fetchAll(
        'SELECT id, allow_node_execution, sort_order
         FROM leigod_lines
         ORDER BY sort_order ASC, id ASC' . $lockSql
    );
    $desiredRows = $db->fetchAll(
        'SELECT DISTINCT ll.id AS leigod_line_id, n.id AS node_id, ll.sort_order AS line_sort_order
         FROM nodes n
         INNER JOIN node_groups ng ON ng.id = n.group_id
         INNER JOIN forward_combinations fc ON fc.node_group_key = ng.group_key
         INNER JOIN forward_combination_leigod_lines fcll ON fcll.forward_combination_id = fc.id
         INNER JOIN leigod_lines ll ON ll.id = fcll.leigod_line_id
         WHERE fc.is_active = 1
           AND fc.backend_mode = :backend_mode
           AND fc.client_mode = :client_mode
           AND fc.sidecar_point_id IS NULL
           AND fcll.enabled = 1
           AND ll.is_active = 1
         ORDER BY ll.sort_order ASC, ll.id ASC, n.id ASC',
        ['backend_mode' => 'leigod_mode', 'client_mode' => 'single']
    );
    $leaseRows = $db->fetchAll(
        'SELECT * FROM leigod_execution_leases ORDER BY leigod_line_id ASC' . $lockSql
    );

    usort($catalogRows, static fn(array $left, array $right): int => [
        (int) ($left['sort_order'] ?? 100),
        (int) ($left['id'] ?? 0),
    ] <=> [
        (int) ($right['sort_order'] ?? 100),
        (int) ($right['id'] ?? 0),
    ]);
    $catalog = [];
    $catalogOrder = [];
    foreach ($catalogRows as $order => $row) {
        $lineId = (int) ($row['id'] ?? 0);
        if ($lineId <= 0 || isset($catalog[$lineId])) {
            continue;
        }
        $catalog[$lineId] = $row;
        $catalogOrder[$lineId] = $order;
    }

    $desired = [];
    $pairCount = 0;
    foreach ($desiredRows as $row) {
        $lineId = (int) ($row['leigod_line_id'] ?? 0);
        $nodeId = (int) ($row['node_id'] ?? 0);
        if ($lineId <= 0 || $nodeId <= 0 || isset($desired[$lineId][$nodeId])) {
            continue;
        }
        $desired[$lineId][$nodeId] = true;
        $pairCount++;
    }
    uksort($desired, static fn(int $left, int $right): int => [
        $catalogOrder[$left] ?? PHP_INT_MAX,
        $left,
    ] <=> [
        $catalogOrder[$right] ?? PHP_INT_MAX,
        $right,
    ]);

    $leases = [];
    foreach ($leaseRows as $row) {
        $lineId = (int) ($row['leigod_line_id'] ?? 0);
        if ($lineId > 0 && !isset($leases[$lineId])) {
            $leases[$lineId] = $row;
        }
    }

    $conflicts = 0;
    $unchanged = 0;
    $toSeed = [];
    foreach ($desired as $lineId => $nodes) {
        $nodeIds = array_map('intval', array_keys($nodes));
        sort($nodeIds, SORT_NUMERIC);
        $line = $catalog[$lineId] ?? null;
        if (count($nodeIds) !== 1
            || !is_array($line)
            || (int) ($line['allow_node_execution'] ?? 1) !== 1) {
            $conflicts++;
            continue;
        }

        $nodeId = $nodeIds[0];
        $existing = $leases[$lineId] ?? null;
        if (!is_array($existing) || leigodExecutionLeaseSeedExpired($existing, $now)) {
            $toSeed[] = ['leigod_line_id' => $lineId, 'node_id' => $nodeId];
            continue;
        }

        if ((string) ($existing['state'] ?? '') !== 'revoking'
            && (string) ($existing['owner_type'] ?? '') === 'node'
            && (int) ($existing['owner_id'] ?? 0) === $nodeId
            && ($existing['owner_device_id'] ?? null) === null) {
            $unchanged++;
            continue;
        }
        $conflicts++;
    }

    return [
        'summary' => [
            'catalog_lines' => count($catalog),
            'desired_pairs' => $pairCount,
            'seeded' => 0,
            'conflicts' => $conflicts,
            'unchanged' => $unchanged,
        ],
        'to_seed' => $toSeed,
    ];
}

/** @param array<string, mixed> $lease */
function leigodExecutionLeaseSeedExpired(array $lease, int $now): bool
{
    $expiresAt = leigodExecutionLeaseSeedTimestamp((string) ($lease['expires_at'] ?? ''));
    if ($expiresAt === null || $expiresAt <= $now) {
        return true;
    }
    if ((string) ($lease['state'] ?? '') === 'pending') {
        $readyDeadline = leigodExecutionLeaseSeedTimestamp((string) ($lease['ready_deadline_at'] ?? ''));
        return $readyDeadline === null || $readyDeadline <= $now;
    }
    return false;
}

function leigodExecutionLeaseSeedTimestamp(string $value): ?int
{
    $value = trim($value);
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d H:i:s') !== $value) {
        return null;
    }
    return $parsed->getTimestamp();
}

/**
 * @param array{catalog_lines:int,desired_pairs:int,seeded:int,conflicts:int,unchanged:int} $summary
 */
function leigodExecutionLeaseSeedSummary(array $summary): string
{
    return sprintf(
        'catalog_lines=%d desired_pairs=%d seeded=%d conflicts=%d unchanged=%d',
        $summary['catalog_lines'],
        $summary['desired_pairs'],
        $summary['seeded'],
        $summary['conflicts'],
        $summary['unchanged'],
    );
}

function leigodExecutionLeaseSeedUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php master/cli/seed-leigod-execution-leases.php [--dry-run]',
        '       php master/cli/seed-leigod-execution-leases.php --apply --confirm=SEED-LEIGOD-LEASES',
    ]) . PHP_EOL;
}

if (!defined('LEIGOD_EXECUTION_LEASE_SEED_LIBRARY_ONLY')) {
    $baseDir = dirname(__DIR__);
    spl_autoload_register(static function (string $class) use ($baseDir): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = $baseDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    try {
        $options = leigodExecutionLeaseSeedParseArguments($argv);
        if ($options['help']) {
            fwrite(STDOUT, leigodExecutionLeaseSeedUsage());
            exit(0);
        }
        $envPath = $baseDir . '/.env';
        if (!is_file($envPath)) {
            throw new RuntimeException('panel_env_missing');
        }
        $config = new Config($envPath);
        $db = new Database($config);
        $leaseService = new LeigodExecutionLeaseService($config, $db);
        $summary = leigodExecutionLeaseSeedRun($options, $db, $leaseService);
        fwrite(STDOUT, leigodExecutionLeaseSeedSummary($summary) . PHP_EOL);
        exit($options['apply'] && $summary['conflicts'] > 0 ? 2 : 0);
    } catch (Throwable $throwable) {
        $message = $throwable->getMessage();
        $error = preg_match('/^[a-z0-9_]+$/', $message) === 1 ? $message : 'seed_failed';
        fwrite(STDERR, 'error=' . $error . PHP_EOL);
        exit(1);
    }
}
