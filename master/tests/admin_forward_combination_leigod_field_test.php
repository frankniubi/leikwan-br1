<?php
declare(strict_types=1);

// The forward-combination admin form exposes leigod as a first-class Forward Mode: a 'leigod_mode'
// option on the backend_mode selector, plus a LeiGod line-id input and a UoT-carrier selector that a
// script shows only when leigod_mode is selected. The standalone self-leg basis picker and its
// LEIGOD_BASIS_ENABLED feature flag are retired (the Forward Mode derives the self-leg). Source-grep
// regression matching the house style of the other admin_* assertions.

$src = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$fail = 0;
function need(string $needle, string $src, int &$fail): void
{
    if (!str_contains($src, $needle)) {
        fprintf(STDERR, "FAIL: AdminController.php missing %s\n", $needle);
        $fail++;
    }
}
function forbid(string $needle, string $src, int &$fail): void
{
    if (str_contains($src, $needle)) {
        fprintf(STDERR, "FAIL: AdminController.php must no longer contain %s\n", $needle);
        $fail++;
    }
}

// Present: leigod_mode is a Forward Mode option + the mode-gated leigod fields.
need('value="leigod_mode"', $src, $fail);
need('name="leigod_line_id"', $src, $fail);
need('name="leigod_uot_impl"', $src, $fail);
need('data-mode-field="leigod_mode"', $src, $fail);
need('$leigodField', $src, $fail);
need('None (dedicated LeiGod forward node)', $src, $fail);
need('Optional for dedicated LeiGod forward-node routes', $src, $fail);
need('aggregation sidecars must be overseas', $src, $fail);

// Retired: the feature flag, standalone self-leg basis picker, and unconditional sidecar requirement.
forbid('LEIGOD_BASIS_ENABLED', $src, $fail);
forbid('name="self_leg_outer_transport"', $src, $fail);
forbid('<select name="sidecar_point_id" required>', $src, $fail);
forbid('<select name="sidecar_point_id" required ', $src, $fail);

if ($fail) {
    fprintf(STDERR, "%d FAIL\n", $fail);
    exit(1);
}
echo "ok\n";
