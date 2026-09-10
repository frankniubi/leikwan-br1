<?php
declare(strict_types=1);

// Source-grep + PHP array simulation guard for the Phase 2 leigod_mode true-MPTCP migration.
//
// The local dev image may not have sqlite PDO, so this test first checks that the SQL contains the
// exact behavioral clauses being simulated, then exercises representative forward_combinations and
// forwards rows in-memory.

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function normalizeSql(string $sql): string
{
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

function assertContains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message . ': missing ' . $needle);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        fail($message . ': got ' . var_export($actual, true) . ', expected ' . var_export($expected, true));
    }
}

/** @param array<int, array<string, mixed>> $rows */
function rowById(array $rows, int $id): array
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }
    fail('missing row id ' . $id);
}

/** @param array<int, array<string, mixed>> $combinations */
function combinationById(array $combinations, int $id): ?array
{
    foreach ($combinations as $combination) {
        if ((int) ($combination['id'] ?? 0) === $id) {
            return $combination;
        }
    }
    return null;
}

/**
 * @param array<int, array<string, mixed>> $combinations
 * @param array<int, array<string, mixed>> $forwards
 * @return array{combinations: array<int, array<string, mixed>>, forwards: array<int, array<string, mixed>>}
 */
function simulateMigration(array $combinations, array $forwards): array
{
    foreach ($combinations as &$combination) {
        $clientMode = $combination['client_mode'] ?? null;
        if (
            ($combination['backend_mode'] ?? null) === 'leigod_mode'
            && ($clientMode === null || !in_array($clientMode, ['mptcp', 'bond'], true))
        ) {
            $combination['client_mode'] = 'mptcp';
            $combination['runtime_preference'] = 'single';
            if ((int) ($combination['helper_group_cap'] ?? 0) <= 0) {
                $combination['helper_group_cap'] = 4;
            }
            $combination['updated_at'] = 'NOW';
        }
    }
    unset($combination);

    foreach ($combinations as &$combination) {
        if (
            ($combination['backend_mode'] ?? null) === 'leigod_mode'
            && ($combination['client_mode'] ?? null) === 'mptcp'
            && ($combination['runtime_preference'] ?? null) === 'mptcp_preferred_bond_fallback'
        ) {
            $combination['runtime_preference'] = 'single';
            if ((int) ($combination['helper_group_cap'] ?? 0) <= 0) {
                $combination['helper_group_cap'] = 4;
            }
            $combination['updated_at'] = 'NOW';
        }
    }
    unset($combination);

    foreach ($forwards as &$forward) {
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $combination = $combinationId > 0 ? combinationById($combinations, $combinationId) : null;
        if (
            $combination !== null
            && ($combination['backend_mode'] ?? null) === 'leigod_mode'
            && in_array(($combination['client_mode'] ?? null), ['mptcp', 'bond'], true)
            && (
                ($forward['client_mode'] ?? null) !== ($combination['client_mode'] ?? null)
                || ($forward['backend_mode'] ?? null) !== ($combination['backend_mode'] ?? null)
                || ($forward['cf_location_group_id'] ?? null) !== null
            )
        ) {
            $forward['client_mode'] = $combination['client_mode'];
            $forward['backend_mode'] = $combination['backend_mode'];
            $forward['cf_location_group_id'] = null;
            $forward['updated_at'] = 'NOW';
        }
    }
    unset($forward);

    return ['combinations' => $combinations, 'forwards' => $forwards];
}

$path = __DIR__ . '/../migrations/20260604_leigod_mode_true_mptcp.sql';
$sql = is_file($path) ? (file_get_contents($path) ?: '') : '';
if ($sql === '') {
    fail('20260604_leigod_mode_true_mptcp.sql is missing or empty');
}

$normalizedSql = normalizeSql($sql);
$capClause = 'helper_group_cap = CASE WHEN helper_group_cap <= 0 THEN 4 ELSE helper_group_cap END';

foreach ([
    'UPDATE forward_combinations',
    'UPDATE forwards f',
    'JOIN forward_combinations fc',
    "SET client_mode = 'mptcp', runtime_preference = 'single', " . $capClause,
    "WHERE backend_mode = 'leigod_mode' AND (client_mode IS NULL OR client_mode NOT IN ('mptcp', 'bond'))",
    "SET runtime_preference = 'single', " . $capClause,
    "AND client_mode = 'mptcp' AND runtime_preference = 'mptcp_preferred_bond_fallback'",
    'f.client_mode = fc.client_mode',
    'f.backend_mode = fc.backend_mode',
    'f.cf_location_group_id = NULL',
] as $snippet) {
    assertContains($normalizedSql, $snippet, 'migration missing required behavioral snippet');
}

assertSame(
    2,
    substr_count($normalizedSql, $capClause),
    'migration must cap helper_group_cap in both leigod legacy upgrade and mptcp fallback normalization'
);

$combinations = [
    [
        'id' => 1,
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'helper_group_cap' => 0,
        'updated_at' => 'old',
    ],
    [
        'id' => 2,
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'helper_group_cap' => 7,
        'updated_at' => 'old',
    ],
    [
        'id' => 3,
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'helper_group_cap' => 0,
        'updated_at' => 'old',
    ],
    [
        'id' => 4,
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'mptcp',
        'runtime_preference' => 'mptcp_preferred_bond_fallback',
        'helper_group_cap' => 0,
        'updated_at' => 'old',
    ],
    [
        'id' => 5,
        'backend_mode' => 'current_mode',
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'helper_group_cap' => 0,
        'updated_at' => 'old',
    ],
];

$forwards = [
    [
        'id' => 101,
        'forward_combination_id' => 1,
        'client_mode' => 'single',
        'backend_mode' => 'current_mode',
        'cf_location_group_id' => 99,
        'updated_at' => 'old',
    ],
    [
        'id' => 102,
        'forward_combination_id' => 3,
        'client_mode' => 'mptcp',
        'backend_mode' => 'cf_mode',
        'cf_location_group_id' => 88,
        'updated_at' => 'old',
    ],
    [
        'id' => 103,
        'forward_combination_id' => null,
        'client_mode' => 'single',
        'backend_mode' => 'cf_mode',
        'cf_location_group_id' => 77,
        'updated_at' => 'old',
    ],
    [
        'id' => 104,
        'forward_combination_id' => 5,
        'client_mode' => 'single',
        'backend_mode' => 'cf_mode',
        'cf_location_group_id' => 66,
        'updated_at' => 'old',
    ],
];

$after = simulateMigration($combinations, $forwards);
$afterCombinations = $after['combinations'];
$afterForwards = $after['forwards'];

$combo = rowById($afterCombinations, 1);
assertSame('mptcp', $combo['client_mode'], 'legacy leigod single combo must upgrade to mptcp');
assertSame('single', $combo['runtime_preference'], 'legacy leigod combo must use single runtime after upgrade');
assertSame(4, $combo['helper_group_cap'], 'legacy leigod combo helper_group_cap=0 must become 4');

$combo = rowById($afterCombinations, 2);
assertSame('mptcp', $combo['client_mode'], 'legacy nonzero-cap combo must upgrade to mptcp');
assertSame(7, $combo['helper_group_cap'], 'legacy nonzero helper_group_cap must be preserved');

$combo = rowById($afterCombinations, 3);
assertSame('bond', $combo['client_mode'], 'explicit leigod bond combo must remain bond');
assertSame(0, $combo['helper_group_cap'], 'explicit leigod bond helper_group_cap must remain unchanged');

$combo = rowById($afterCombinations, 4);
assertSame('mptcp', $combo['client_mode'], 'explicit leigod mptcp combo must remain mptcp');
assertSame('single', $combo['runtime_preference'], 'leigod mptcp fallback runtime must become single');
assertSame(4, $combo['helper_group_cap'], 'leigod mptcp fallback helper_group_cap=0 must become 4');

assertSame(rowById($combinations, 5), rowById($afterCombinations, 5), 'non-leigod combination must remain untouched');

$forward = rowById($afterForwards, 101);
assertSame('mptcp', $forward['client_mode'], 'combo-backed leigod forward must sync client_mode');
assertSame('leigod_mode', $forward['backend_mode'], 'combo-backed leigod forward must sync backend_mode');
assertSame(null, $forward['cf_location_group_id'], 'combo-backed leigod forward must clear cf_location_group_id');

$forward = rowById($afterForwards, 102);
assertSame('bond', $forward['client_mode'], 'combo-backed explicit bond forward must sync client_mode');
assertSame('leigod_mode', $forward['backend_mode'], 'combo-backed explicit bond forward must sync backend_mode');
assertSame(null, $forward['cf_location_group_id'], 'combo-backed explicit bond forward must clear cf_location_group_id');

assertSame(rowById($forwards, 103), rowById($afterForwards, 103), 'non-combo forward must remain untouched');
assertSame(rowById($forwards, 104), rowById($afterForwards, 104), 'non-leigod combo-backed forward must remain untouched');

$afterSecondRun = simulateMigration($afterCombinations, $afterForwards);
assertSame($after, $afterSecondRun, 'rerunning migration simulation must be idempotent');

echo "OK\n";
exit(0);
