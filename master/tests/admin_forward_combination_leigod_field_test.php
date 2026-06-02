<?php
declare(strict_types=1);

// A7: the forward-combination admin form exposes a leigod self-leg basis picker + line id input,
// gated behind LEIGOD_BASIS_ENABLED, and the fields flow through saveForwardCombination unchanged
// (the handler passes $request->body() straight to the service). Source-grep regression, matching
// the house style of the other admin_* source assertions.

$src = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$fail = 0;
function need(string $needle, string $src, int &$fail): void
{
    if (!str_contains($src, $needle)) {
        fprintf(STDERR, "FAIL: AdminController.php missing %s\n", $needle);
        $fail++;
    }
}

need("LEIGOD_BASIS_ENABLED", $src, $fail);
need('name="self_leg_outer_transport"', $src, $fail);
need('leigod (LeiGod line)', $src, $fail);
need('name="leigod_line_id"', $src, $fail);
need('$leigodField', $src, $fail);

if ($fail) {
    fprintf(STDERR, "%d FAIL\n", $fail);
    exit(1);
}
echo "ok\n";
