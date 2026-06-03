<?php
declare(strict_types=1);
// Asserts the HK/JP route seed creates both routes + links combos 1 (HK) and 2 (JP), idempotently.
$sql = file_get_contents(__DIR__ . '/../migrations/20260602_seed_hk_jp_routes.sql') ?: '';
$fail = 0;
function need(string $n, string $s, int &$f): void { if (!str_contains($s, $n)) { fwrite(STDERR, "FAIL missing: $n\n"); $f++; } }
need("'HK'", $sql, $fail);
need("'JP'", $sql, $fail);
need('forward_combination_id, enabled', $sql, $fail);
need('fr.name = \'HK\'', $sql, $fail);
need('fr.name = \'JP\'', $sql, $fail);
if (substr_count($sql, 'NOT EXISTS') < 4) { fwrite(STDERR, "FAIL: not idempotent\n"); $fail++; }
if ($fail) exit(1);
echo "ok\n";
