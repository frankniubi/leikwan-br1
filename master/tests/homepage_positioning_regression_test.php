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
    'Bond speed. Private paths. Clean control.',
    'One busy session should not crawl because one route is full.',
    '1 flow',
    'many legs',
    'IXP fabric',
    'IPLC paths',
    'Policy proof',
    'Client',
    'Slave',
    'Transport',
    'Target',
    'Bond speed',
    'Private paths',
    'Clean control',
    'Single-thread bond speed',
    'IXP + IPLC routing',
    'Lower regulatory exposure',
    'Tenant console',
    'Open the panel that controls services, forwards, client profiles, transport endpoints, and route policy.',
    'Blend exchange fabric and private-line paths by tenant plan, node group, region, and target transport policy.',
    'LeiKwan Bridge bonds IXP, IPLC, slave nodes, client profiles, and transport endpoints into one policy-visible delivery plane.',
    'Install flow, route modes, diagnostics, transport endpoint rollout, and recovery notes live in the panel docs.',
    'Operator setup',
    'Open Docs',
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
    'Bonded IXP/IPLC acceleration for single-thread traffic.',
    'Fast paths, visible policy, controlled delivery.',
    'Open the panel that controls services, forwards, clients, sidecars, and route policy.',
    'Blend exchange fabric and private-line paths by tenant plan, node group, region, and sidecar target.',
    'LeiKwan Bridge bonds IXP, IPLC, slave nodes, clients, and sidecars into one policy-visible delivery plane.',
    'Install flow, route modes, diagnostics, sidecar rollout, and recovery notes live in the panel docs.',
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
    '.launch-hero',
    '.flow-visual',
    '.flow-band',
    '.proof-grid',
    '.launch-entry',
] as $styleSnippet) {
    if (!str_contains($base, $styleSnippet)) {
        fail('missing homepage style snippet: ' . $styleSnippet);
    }
}

foreach ([
    'Bond speed. Private paths. Clean control.',
    'Single-thread bond speed',
    'IXP + IPLC routing',
    'Lower regulatory exposure',
    'Tenant console',
] as $localeKey) {
    foreach (['zh-TW' => $zhTw, 'zh-Hans' => $zhHans] as $locale => $dictionary) {
        $value = (string) (($dictionary['text'] ?? [])[$localeKey] ?? '');
        if ($value === '') {
            fail('missing homepage locale key for ' . $locale . ': ' . $localeKey);
        }
        if (str_contains($value, "\u{FFFD}") || str_contains($value, '?')) {
            fail('corrupted homepage locale value for ' . $locale . ': ' . $localeKey);
        }
    }
}

echo "ok\n";
