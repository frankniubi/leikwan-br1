<?php
declare(strict_types=1);

$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/operator-console.css');
$normalizedCss = preg_replace('/\s+/', ' ', $css) ?? $css;
if ($controller === '' || $css === '') {
    fwrite(STDERR, "failed to read Client page style sources\n");
    exit(1);
}

$start = strpos($controller, 'public function clientsPage(');
$end = strpos($controller, 'public function resetDefaultClientToken(', $start ?: 0);
$page = ($start !== false && $end !== false) ? substr($controller, $start, $end - $start) : '';

foreach ([
    'tenant-client-install',
    'tenant-client-install__shell',
    'tenant-client-install__header',
    'tenant-client-install__platforms',
    'tenant-client-install__platform',
    'tenant-client-install__online',
] as $className) {
    if (!str_contains($page, $className) || !str_contains($normalizedCss, '.' . $className)) {
        fwrite(STDERR, 'missing approved Client layout class: ' . $className . "\n");
        exit(1);
    }
}

foreach ([
    'max-width:760px',
    'grid-template-columns:repeat(2,minmax(0,1fr))',
    '@media (max-width: 700px)',
    'grid-template-columns:1fr',
] as $requiredCss) {
    if (!str_contains($normalizedCss, $requiredCss)) {
        fwrite(STDERR, 'missing responsive Client layout rule: ' . $requiredCss . "\n");
        exit(1);
    }
}

echo "ok\n";
