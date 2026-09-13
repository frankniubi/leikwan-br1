<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$home = (string) file_get_contents($root . '/src/Support/HomePageContent.php');
$css = (string) file_get_contents($root . '/public/assets/bridge-home.css');

foreach ([
    'HomePageContent::render(',
    'LocaleResolver::resolve($request)',
    'return $this->publicPage($this->displayBrandName(), $body, $user, \'bridge-home\');',
] as $snippet) {
    if (!str_contains($auth, $snippet)) {
        fail('homepage controller must delegate to the localized content renderer: ' . $snippet);
    }
}

foreach ([
    "'en' => [",
    "'zh-Hans' => [",
    "'zh-TW' => [",
    'class="bridge-home-hero"',
    'class="bridge-home-board"',
    'class="bridge-home-steps"',
    'class="bridge-home-step-list"',
    'class="bridge-home-path"',
    'class="bridge-home-path-rail"',
    'class="bridge-home-closing"',
    '127.0.0.1',
] as $snippet) {
    if (!str_contains($home, $snippet)) {
        fail('missing localized homepage snippet: ' . $snippet);
    }
}

foreach ([
    'signal-hero',
    'signal-canvas',
    'signal-summary',
    'signal-light-detail',
    'signal-architecture',
    'bridge-home-metrics',
    'Multi-Slave Transport',
    'Slave 节点',
    'SIDECAR',
    'Sidecar',
    'MPTCP',
    'client_strategy',
    'Latency Best',
    'Speed max',
    'Open Console',
    'View Network',
    '活跃节点',
    '覆盖区域',
    '边缘端点',
    '管理员账户',
    '利群會聚 <span>Bridge Control</span>',
    '分布式传输，清晰可控。',
    '每条路径都应该能被解释。',
    '鍒╃兢',
] as $forbidden) {
    if (str_contains($auth . $home, $forbidden)) {
        fail('homepage keeps retired or unreadable content: ' . $forbidden);
    }
}

foreach (['SELECT COUNT(*) AS total FROM nodes', 'sidecar_points', 'COUNT(DISTINCT region)'] as $forbiddenQuery) {
    if (str_contains($auth . $home, $forbiddenQuery)) {
        fail('homepage must not read live business data: ' . $forbiddenQuery);
    }
}

foreach ([
    '.bridge-home-page',
    '.bridge-home-hero',
    '.bridge-home-board',
    '.bridge-home-steps',
    '.bridge-home-step',
    '.bridge-home-path',
    '.bridge-home-stop',
    '.bridge-home-closing',
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

if (preg_match('/\.bridge-home-page \.site-mark\s*\{[^}]*border:/s', $css) === 1) {
    fail('bridge home still frames the logo with a dark square border');
}

echo "ok
";
