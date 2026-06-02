<?php
declare(strict_types=1);

// B6: the backfill wraps each legacy combination in a singleton race-disabled route, links it,
// binds legacy forwards, and migrates plan gating. Idempotency is enforced via NOT EXISTS guards.
// Static assertion over the migration (no DB).

$sql = file_get_contents(__DIR__ . '/../migrations/20260602_backfill_forward_routes.sql') ?: '';
$fail = 0;
function need(string $needle, string $sql, int &$fail): void
{
    if (!str_contains($sql, $needle)) {
        fprintf(STDERR, "FAIL: backfill missing %s\n", $needle);
        $fail++;
    }
}

need('INSERT INTO forward_routes', $sql, $fail);
need('INSERT INTO forward_route_combinations', $sql, $fail);
need('UPDATE forwards', $sql, $fail);
need('SET f.forward_route_id = fr.id', $sql, $fail);
need('active_combination_id = f.forward_combination_id', $sql, $fail);
need('service_plan_forward_routes', $sql, $fail);
// idempotency guards
if (substr_count($sql, 'NOT EXISTS') < 4) {
    fprintf(STDERR, "FAIL: backfill must guard each step with NOT EXISTS (idempotency)\n");
    $fail++;
}

if ($fail) {
    fprintf(STDERR, "%d FAIL\n", $fail);
    exit(1);
}
echo "ok\n";
