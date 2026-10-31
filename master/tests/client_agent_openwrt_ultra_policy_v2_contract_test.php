<?php
declare(strict_types=1);

function failOpenWrtUltraPolicy(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    failOpenWrtUltraPolicy('failed to read ClientAgentController');
}

// leikwand sends X-Device-Platform: openwrt (or degrades to linux on an old
// panel). The router speaks the same ultra-client runtime as Linux, so the
// pull must hand it the v2 grant shape with the session_ticket; without this
// the lease endpoint refuses with lease_scope_invalid.
if (!str_contains($source, "str_starts_with(\$platform, 'openwrt')")) {
    failOpenWrtUltraPolicy('OpenWrt pull requests must select the Ultra v2 policy path');
}

if (!str_contains($source, "\$platform === 'router'")) {
    failOpenWrtUltraPolicy('the router form-factor tag must select Ultra v2 as well');
}

// The router platform must still be refused an ultra grant the same way every
// other platform is: the v2 gate lives next to the android request gate, and
// neither weakens the other checks.
if (!str_contains($source, 'private static function usesUltraPolicyV2(string $platform): bool')) {
    failOpenWrtUltraPolicy('usesUltraPolicyV2 gate is missing');
}

echo "ok\n";
