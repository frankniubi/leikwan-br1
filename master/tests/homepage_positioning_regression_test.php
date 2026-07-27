<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$css = (string) file_get_contents($root . '/public/assets/bridge-home.css');

foreach ([
    'class="bridge-home-hero"',
    '利群會聚 <span>Bridge Control</span>',
    '分布式传输，清晰可控。',
    '进入控制台',
    '查看网络路径',
    '活跃节点',
    '覆盖区域',
    '边缘端点',
    '管理员账户',
    'class="bridge-home-route"',
    '每条路径都应该能被解释。',
    "'bridge-home'",
] as $snippet) {
    if (!str_contains($auth, $snippet)) {
        fail('missing bridge home snippet: ' . $snippet);
    }
}

foreach ([
    'signal-hero',
    'signal-canvas',
    'signal-summary',
    'signal-light-detail',
    'signal-architecture',
    'Multi-Slave Transport',
    'Open Console',
    'View Network',
    '鍒╃兢',
] as $forbidden) {
    if (str_contains($auth, $forbidden)) {
        fail('old or unreadable homepage content remains: ' . $forbidden);
    }
}

foreach ([
    '.bridge-home-page',
    '.bridge-home-hero',
    '.bridge-home-status',
    '.bridge-home-metrics',
    '.bridge-home-route',
    '.bridge-home-footer-grid',
    '.bridge-home-page .site-mark',
    '--bridge-paper: #ffffff',
    'border-radius: 999px',
    '@media (max-width: 820px)',
] as $selector) {
    if (!str_contains($css, $selector)) {
        fail('missing bridge home style selector: ' . $selector);
    }
}

foreach (['linear-gradient(', 'radial-gradient(', 'clamp('] as $forbiddenCss) {
    if (str_contains($css, $forbiddenCss)) {
        fail('bridge home uses forbidden visual primitive: ' . $forbiddenCss);
    }
}

if (str_contains($css, 'border: 1px solid var(--bridge-ink)')) {
    fail('bridge home still frames the logo with a dark square border');
}

echo "ok\n";
