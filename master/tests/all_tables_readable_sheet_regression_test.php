<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'src/Controllers/AdminController.php',
    'src/Controllers/TenantController.php',
    'src/Controllers/BaseController.php',
    'src/Controllers/AuthController.php',
    'src/Controllers/HealthController.php',
    'public/install.php',
];

foreach ($files as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $tableCount = substr_count($source, '<table');
    preg_match_all('/(?:table-wrap|table-scroll|pg-sheet-scroll)[^<]*[\s\S]{0,500}?<table/', $source, $matches);
    if (count($matches[0]) !== $tableCount) {
        throw new RuntimeException($relative . ' has an unbounded table: total=' . $tableCount . ' wrapped=' . count($matches[0]));
    }
}

$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
if (!str_contains($base, 'class="pg-console')) {
    throw new RuntimeException('application page bodies are not wrapped by pg-console');
}

echo "ok\n";
