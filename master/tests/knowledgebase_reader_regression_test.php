<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';
$auth = file_get_contents($root . '/src/Controllers/AuthController.php');
$tenant = file_get_contents($root . '/src/Controllers/TenantController.php');
$runtimePath = $root . '/public/assets/knowledgebase-mermaid.js';
$bundlePath = $root . '/public/assets/vendor/mermaid/mermaid.min.js';
$licensePath = $root . '/public/assets/vendor/mermaid/LICENSE';
$packagePath = $root . '/public/assets/vendor/mermaid/package.json';

if (!is_string($auth) || $auth === '') {
    fail('failed to read AuthController source');
}
if (!is_string($tenant) || $tenant === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'new KnowledgebaseService($this->db)',
    'LocaleResolver::resolve($request)',
    'listPublishedTopics($locale)',
    'findPublishedTopicByKey($selectedTopicKey, $locale)',
    "(bool) (\$selectedTopic['used_fallback'] ?? false)",
    "'getting-started-five-minute-overview'",
    "'install-client'",
    "'getting-started-first-forward-checklist'",
    "'connect-and-verify'",
    "'Start here'",
    "'Learn as needed'",
    'KnowledgebaseHtmlSanitizer',
    'KnowledgebaseOutline',
    "str_contains(\$articleBody, '<pre class=\"mermaid\">')",
    '/assets/knowledgebase-docs.css?v=20260914a',
    '/assets/vendor/mermaid/mermaid.min.js?v=11.12.0',
    '/assets/knowledgebase-mermaid.js?v=20260914a',
    '?thread=',
    "'<span class=\"kb-updated\"><span>Updated</span> <time>'",
    "'<span><span>Updated</span> <time>'",
] as $required) {
    if (!str_contains($auth, $required)) {
        fail('missing localized guide reader snippet: ' . $required);
    }
}

foreach ([
    'listPublishedThreads()',
    'findPublishedThreadBySlug',
    'Latest Threads',
    'Threaded operational docs',
    'https://cdn.jsdelivr.net/npm/mermaid',
    'Slug: ',
] as $forbidden) {
    if (str_contains($auth, $forbidden)) {
        fail('found stale or unsafe reader snippet: ' . $forbidden);
    }
}

if (preg_match('/private const START_HERE_TOPIC_KEYS = \[(.*?)\];/s', $auth, $startHereMatch) !== 1) {
    fail('missing Start here topic key declaration');
}
preg_match_all("/'([^']+)'/", $startHereMatch[1], $startHereKeys);
if (($startHereKeys[1] ?? []) !== [
    'getting-started-five-minute-overview',
    'install-client',
    'getting-started-first-forward-checklist',
    'connect-and-verify',
]) {
    fail('Start here must contain exactly the four approved topic keys in service order');
}

foreach ([
    'getting-started-five-minute-overview',
    'install-client',
    'getting-started-first-forward-checklist',
    'connect-and-verify',
    'troubleshooting-common-mistakes',
    'glossary',
    'no-gui-clients',
] as $stableThreadValue) {
    if (!str_contains($auth . $tenant, $stableThreadValue)) {
        fail('missing stable guide thread value: ' . $stableThreadValue);
    }
}

$docsCssPath = $root . '/public/assets/knowledgebase-docs.css';
if (!is_file($docsCssPath)) {
    fail('missing shared documentation stylesheet: ' . $docsCssPath);
}
$docsCss = file_get_contents($docsCssPath);
if (!is_string($docsCss) || trim($docsCss) === '') {
    fail('shared documentation stylesheet is empty');
}
foreach ([
    '.kb-docs .kb-layout {',
    '.kb-docs .kb-article-body {',
    '.kb-docs .kb-article-body figure',
    '.kb-docs .kb-article-body figure img',
    '.kb-docs .kb-article-body blockquote',
    '.kb-docs .kb-article-body table',
    '.kb-docs .kb-article-body pre.mermaid',
    '.kb-docs .kb-mermaid-figure svg',
    '.kb-docs .kb-toc',
    'max-width: 100%;',
] as $requiredRule) {
    if (!str_contains($docsCss, $requiredRule)) {
        fail('missing documentation stylesheet rule: ' . $requiredRule);
    }
}
foreach (['!important', 'border-radius: 999px'] as $tolerated) {
    // The stylesheet intentionally overrides the shared console skin; both markers must stay intentional.
    if (!str_contains($docsCss, $tolerated)) {
        fail('documentation stylesheet lost its console-skin override marker: ' . $tolerated);
    }
}

foreach ([$runtimePath, $bundlePath, $licensePath, $packagePath] as $requiredFile) {
    if (!is_file($requiredFile)) {
        fail('missing local Mermaid asset: ' . $requiredFile);
    }
}

$runtime = file_get_contents($runtimePath);
$bundle = file_get_contents($bundlePath);
$packageJson = file_get_contents($packagePath);
if (!is_string($runtime) || !is_string($bundle) || !is_string($packageJson)) {
    fail('failed to read local Mermaid assets');
}

foreach ([
    'querySelectorAll("pre.mermaid")',
    'securityLevel: "strict"',
    'htmlLabels: false',
    'await window.mermaid.render',
    'source.replaceWith(figure)',
    'source.classList.add("kb-mermaid-failed")',
    'source.dataset.errorLabel',
    'source.getAttribute("data-error-label")',
    'svg.setAttribute("role", "img")',
    'svg.querySelector("title[id]")',
    'svg.querySelector("desc[id]")',
    'svg.setAttribute("aria-labelledby", labelledBy.join(" "))',
    'document.createElementNS("http://www.w3.org/2000/svg", "title")',
    'svg.removeAttribute("height")',
    'svg.style.maxWidth = "100%"',
    'svg.style.height = "auto"',
] as $requiredRuntimeSnippet) {
    if (!str_contains($runtime, $requiredRuntimeSnippet)) {
        fail('missing Mermaid runtime behavior: ' . $requiredRuntimeSnippet);
    }
}

if (!str_contains($bundle, '11.12.0')) {
    fail('Mermaid browser bundle is not exact version 11.12.0');
}

try {
    $package = json_decode($packageJson, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('invalid vendored Mermaid package.json: ' . $exception->getMessage());
}
if (($package['name'] ?? null) !== 'mermaid' || ($package['version'] ?? null) !== '11.12.0') {
    fail('vendored package metadata is not mermaid@11.12.0');
}

$readerAssetTags = implode("\n", array_filter(explode("\n", $auth), static fn (string $line): bool => str_contains($line, 'mermaid')));
if (str_contains($readerAssetTags, 'http://') || str_contains($readerAssetTags, 'https://')) {
    fail('reader Mermaid asset tags must not reference the network');
}

foreach ([
    '#quick-start',
    '#advanced-options',
    '#usage-patterns',
    'The Default Path /',
    'Core Modes /',
    'Three Common Goals /',
] as $forbidden) {
    if (str_contains($auth . $tenant, $forbidden)) {
        fail('found stale static knowledgebase snippet: ' . $forbidden);
    }
}

echo "ok\n";
