<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

// The flag is resolved in one place.
if (substr_count($base, "config->get('LEIKWAN_SKIN'") !== 1) {
    throw new RuntimeException('LEIKWAN_SKIN must be read in exactly one method');
}
if (!str_contains($base, 'protected function activeSkin(?array $viewer = null): string')) {
    throw new RuntimeException('activeSkin() accessor is missing');
}

// Default is legacy: nothing changes for users until the cutover.
if (!str_contains($base, "config->get('LEIKWAN_SKIN', 'legacy')")) {
    throw new RuntimeException('LEIKWAN_SKIN must default to legacy');
}

// Unknown values must not silently enable v2.
if (!str_contains($base, "=== 'v2' ? 'v2' : 'legacy'")) {
    throw new RuntimeException('skin resolution must whitelist v2 rather than blacklist legacy');
}

// The ?skin= override is admin-only and session-persisted.
foreach (["\$_GET['skin']", "'admin'", 'skin_override'] as $needle) {
    if (!str_contains($base, $needle)) {
        throw new RuntimeException('admin skin override contract missing: ' . $needle);
    }
}

echo "ok\n";
