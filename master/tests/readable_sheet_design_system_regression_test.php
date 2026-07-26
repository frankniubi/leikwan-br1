<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';

use Leikwan\Master\Support\PrecisionGridUi;

$tabs = PrecisionGridUi::viewTabs([
    ['key' => 'nodes', 'label' => 'Nodes', 'href' => '/admin/nodes?view=nodes'],
    ['key' => 'install', 'label' => 'Install', 'href' => '/admin/nodes?view=install'],
], 'nodes');
foreach (['role="tablist"', 'aria-selected="true"', 'data-view="nodes"'] as $needle) {
    if (!str_contains($tabs, $needle)) {
        throw new RuntimeException('tab contract missing ' . $needle);
    }
}

$status = PrecisionGridUi::status('Healthy', 'ok');
if (!str_contains($status, 'pg-status-dot') || !str_contains($status, '>Healthy<')) {
    throw new RuntimeException('status must combine symbol and text');
}

$cell = PrecisionGridUi::resourceCell('shanghai-01', '49.235.160.246');
if (!str_contains($cell, 'pg-resource-primary') || !str_contains($cell, 'pg-resource-secondary')) {
    throw new RuntimeException('resource cell hierarchy missing');
}

$menu = PrecisionGridUi::rowMenu('shanghai-01 actions', '<a href="/edit">Edit</a>');
if (!str_contains($menu, '<details') || !str_contains($menu, 'aria-label="shanghai-01 actions"')) {
    throw new RuntimeException('row menu accessibility missing');
}

$css = (string) file_get_contents(__DIR__ . '/../public/assets/leikwan-v2.css');
foreach (['.pg-sheet', '.pg-sheet-table', '.pg-mobile-resource-row', 'font-variant-numeric:tabular-nums', 'height:var(--row-h)'] as $needle) {
    if (!str_contains(str_replace(' ', '', $css), str_replace(' ', '', $needle))) {
        throw new RuntimeException('Sheet CSS contract missing ' . $needle);
    }
}

echo "ok\n";
