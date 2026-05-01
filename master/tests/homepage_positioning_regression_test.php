<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$auth = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$zhTw = json_decode((string) file_get_contents(__DIR__ . '/../public/i18n/zh-TW.json'), true);
$zhHans = json_decode((string) file_get_contents(__DIR__ . '/../public/i18n/zh-Hans.json'), true);

if (!is_string($auth) || $auth === '') {
    fail('failed to read AuthController source');
}
if (!is_string($base) || $base === '') {
    fail('failed to read BaseController source');
}
if (!is_array($zhTw) || !is_array($zhHans)) {
    fail('failed to load locale dictionaries');
}

$brandName = "\u{5229}\u{7FA4}\u{6703}\u{805A} LeiKwan Bridge";
$requiredHomepageSnippets = [
    $brandName,
    'Bonded IXP/IPLC acceleration for single-thread traffic.',
    'Single-thread bond speed',
    'Split one busy flow across controlled paths so one download, stream, or tunnel can use more than one route.',
    'IXP + IPLC routing',
    'Blend exchange access and private-line capacity by region, node group, tenant plan, and sidecar target.',
    'Lower regulatory exposure',
    'Use auditable private or partner paths with explicit routing policy instead of opaque public-network bypass.',
    'Tenant-ready control plane',
    'Keep admin plans, tenant services, client config, sidecars, and slave nodes in one delivery loop.',
    'Bond single flows',
    'Choose IXP/IPLC paths',
    'Deliver with policy',
];

foreach ($requiredHomepageSnippets as $snippet) {
    if (!str_contains($auth, $snippet)) {
        fail('missing homepage positioning snippet: ' . $snippet);
    }
}

foreach ([
    'Short homepage by design',
    'Lightweight IXP control plane.',
    'Use this page as the front door. Real work continues inside the tenant or admin console.',
] as $removedSnippet) {
    if (str_contains($auth, $removedSnippet)) {
        fail('old homepage description still present: ' . $removedSnippet);
    }
}

if (stripos($auth, 'no regulation risk') !== false) {
    fail('homepage should avoid absolute "no regulation risk" claims');
}

if (str_contains($auth, '????')) {
    fail('homepage contains mojibake/question-mark replacement text');
}

foreach ([
    '.feature-mosaic',
    '.feature-card',
    '.route-card',
    '.metric-strip',
] as $styleSnippet) {
    if (!str_contains($base, $styleSnippet)) {
        fail('missing homepage style snippet: ' . $styleSnippet);
    }
}

foreach ([
    'Bonded IXP/IPLC acceleration for single-thread traffic.',
    'Single-thread bond speed',
    'IXP + IPLC routing',
    'Lower regulatory exposure',
    'Tenant-ready control plane',
] as $localeKey) {
    foreach (['zh-TW' => $zhTw, 'zh-Hans' => $zhHans] as $locale => $dictionary) {
        $value = (string) (($dictionary['text'] ?? [])[$localeKey] ?? '');
        if ($value === '') {
            fail('missing homepage locale key for ' . $locale . ': ' . $localeKey);
        }
        if (str_contains($value, '?') || str_contains($value, '�')) {
            fail('corrupted homepage locale value for ' . $locale . ': ' . $localeKey);
        }
    }
}

echo "ok\n";
