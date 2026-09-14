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
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

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
    'class="bridge-home-map"',
    'class="bridge-home-network"',
    'class="bridge-home-plate-key"',
    'bridge-home-title-zh',
    'bridge-home-title-en',
    'rk-link-mesh',
    'BGP',
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
    'Every region, measured.',
    'WGS 84',
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
    '.bridge-home-map',
    '.bridge-home-network',
    '.bridge-home-tag',
    '.bridge-home-page .site-mark',
    '--bridge-paper: #ffffff',
    'border-radius: 999px',
    '@media (max-width: 900px)',
    'inter-var.ttf',
    'space-grotesk-var.ttf',
    'background: #ffffff',
    '--bridge-orange: #f6821f',
    '--bridge-orange-hover: #f48120',
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

if (!str_contains($base, '/assets/bridge-home.css?v=20260915N')) {
    fail('homepage stylesheet cache key was not bumped');
}

// The homepage is a light surface now: a dark band or a near-black fill is the
// regression this guards, so every literal background must be a light colour.
foreach (['--bridge-navy:', '--bridge-foil:'] as $retiredToken) {
    if (str_contains($css, $retiredToken)) {
        fail('homepage keeps a retired dark-theme token: ' . $retiredToken);
    }
}

preg_match_all('/background(?:-color)?:\s*#([0-9a-fA-F]{6})/', $css, $backgrounds);
// White, the two whisper greys, the orange tints, plus the 7px status dot.
$lightSurfaces = ['ffffff', 'f7f7f7', 'fafafa', 'fff5eb', 'fef3e8', '1a7f45'];
foreach (array_unique($backgrounds[1]) as $hex) {
    if (!in_array(strtolower($hex), $lightSurfaces, true)) {
        fail('homepage paints a background that is not a light surface: #' . $hex);
    }
}

if (!str_contains($css, 'background: var(--bridge-paper)')) {
    fail('homepage page background must resolve to the white paper token');
}

if (str_contains($base, '/assets/brand-mark-foil.png')) {
    fail('homepage must use the full-colour brand mark, not the cropped foil cut');
}

if (!str_contains($base, '<strong lang="zh-Hant">利群會聚</strong>')) {
    fail('homepage brand lockup must keep Chinese and English as siblings');
}

if (!str_contains($base, '<span lang="en">LeiKwan Bridge</span>')) {
    fail('homepage English wordmark must be LeiKwan Bridge in full');
}

if (!str_contains($base, '登录 · Sign In')) {
    fail('homepage sign-in must keep Chinese and English in one control');
}

echo "ok
";
