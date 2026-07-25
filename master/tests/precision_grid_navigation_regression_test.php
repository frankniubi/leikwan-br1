<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/PrecisionGridUi.php';

use Leikwan\Master\Support\PrecisionGridUi;

$html = PrecisionGridUi::groupNavigation([
    ['group' => 'Operations', 'href' => '/admin', 'label' => 'Overview'],
    ['group' => 'Infrastructure', 'href' => '/admin/nodes', 'label' => 'Slave Nodes'],
]);
foreach (['data-nav-group="Operations"', 'data-nav-group="Infrastructure"', 'aria-current="page"'] as $required) {
    if (!str_contains($html, $required) && $required !== 'aria-current="page"') {
        throw new RuntimeException('missing grouped navigation marker ' . $required);
    }
}

$header = PrecisionGridUi::pageHeader('Operations', '<unsafe>', '<button>Run</button>');
$metrics = PrecisionGridUi::metricStrip([['label' => 'Nodes', 'value' => '6', 'state' => 'ok']]);
$empty = PrecisionGridUi::emptyState('No services');
$section = PrecisionGridUi::sectionHeader('Runtime', 'current');
foreach (['pg-page-header', '&lt;unsafe&gt;', 'pg-metric-strip', 'pg-empty-state', 'pg-section-header'] as $required) {
    if (!str_contains($header . $metrics . $empty . $section, $required)) {
        throw new RuntimeException('missing primitive ' . $required);
    }
}

$base = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/BaseController.php');
$navMethod = substr($base, strpos($base, 'protected function adminNavigationItems'), 3500);
foreach (['Cloudflare Location Groups', 'Forward Combinations', 'Forward Routes', "'label' => 'Chains'"] as $retired) {
    if (str_contains($navMethod, $retired)) {
        throw new RuntimeException('retired navigation returned: ' . $retired);
    }
}

echo "ok\n";
