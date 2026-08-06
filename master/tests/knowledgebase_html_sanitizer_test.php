<?php
declare(strict_types=1);

use Leikwan\Master\Support\KnowledgebaseHtmlSanitizer;

require_once __DIR__ . '/../src/Support/KnowledgebaseHtmlSanitizer.php';

function failSanitizer(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSanitizerContains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        failSanitizer($label . ': missing ' . $needle . ' in ' . $haystack);
    }
}

function assertSanitizerNotContains(string $needle, string $haystack, string $label): void
{
    if (stripos($haystack, $needle) !== false) {
        failSanitizer($label . ': unexpectedly contained ' . $needle . ' in ' . $haystack);
    }
}

$sanitizer = new KnowledgebaseHtmlSanitizer();

$dirty = '<h2 onclick="alert(1)">Title</h2>'
    . '<script>alert(1)</script><style>body{display:none}</style>'
    . '<iframe src="https://evil.example">frame text</iframe>'
    . '<object>object text</object>'
    . '<p style="position:fixed">Safe <strong>text</strong> <a href="javascript:alert(1)">bad</a></p>'
    . '<custom data-danger="yes">Unwrapped <em>content</em></custom>'
    . '<pre class="mermaid">flowchart TD' . "\n" . 'A[Start] --&gt; B[Done]</pre>'
    . '<pre class="mermaid other">mixed</pre><pre class=" MERMAID ">case</pre>'
    . '<figure class="kb-screenshot-figure"><img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" alt="Guide" width="1440" height="960" onerror="alert(1)"><figcaption>Demo caption</figcaption></figure>'
    . '<img src="/uploads/knowledgebase/guide.png" alt="Guide" width="640" height="480" onerror="alert(1)">';
$clean = $sanitizer->sanitize($dirty);

assertSanitizerContains('<h2>Title</h2>', $clean, 'allowed heading');
assertSanitizerContains('<strong>text</strong>', $clean, 'allowed inline markup');
assertSanitizerContains('Unwrapped <em>content</em>', $clean, 'harmless unknown element unwrap');
assertSanitizerContains('<pre class="mermaid">', $clean, 'exact Mermaid class');
assertSanitizerContains('<pre>mixed</pre>', $clean, 'mixed Mermaid class loses class');
assertSanitizerContains('<pre>case</pre>', $clean, 'class matching is case-sensitive after whitespace normalization');
assertSanitizerContains('<img src="/uploads/knowledgebase/guide.png" alt="Guide" width="640" height="480">', $clean, 'local knowledgebase image');
assertSanitizerContains('<figure><img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" alt="Guide" width="1440" height="960"><figcaption>Demo caption</figcaption></figure>', $clean, 'tenant screenshot figure');
foreach (['onclick', '<script', 'alert(1)', '<style', 'display:none', '<iframe', 'frame text', '<object', 'object text', 'style=', 'javascript:', 'onerror', '<custom', 'data-danger', 'other'] as $forbidden) {
    assertSanitizerNotContains($forbidden, $clean, 'dangerous markup removal');
}

$links = $sanitizer->sanitize(
    '<a href="/guide/start" title="Local" target="_blank">local</a>'
    . '<a href="https://docs.example/path">https</a>'
    . '<a href="http://docs.example/path">http</a>'
    . '<a href="mailto:help@example.com">mail</a>'
    . '<img src="https://cdn.example/guide.png" alt="Remote">'
);
foreach (['href="/guide/start"', 'href="https://docs.example/path"', 'href="http://docs.example/path"', 'href="mailto:help@example.com"', 'src="https://cdn.example/guide.png"'] as $allowed) {
    assertSanitizerContains($allowed, $links, 'approved URL');
}
assertSanitizerNotContains('target=', $links, 'unapproved target attribute');
assertSanitizerNotContains('<embed', $sanitizer->sanitize('<p>before</p><embed src="bad"><p>after</p>'), 'dangerous void element removal');

$badUrls = [
    '//evil.example/path',
    '&#47;&#47;evil.example/path',
    '%2f%2fevil.example/path',
    '/uploads/knowledgebase/../secret.png',
    '/uploads/knowledgebase/%2e%2e/secret.png',
    '/uploads/knowledgebase/%252e%252e/secret.png',
    '/uploads/knowledgebase\\evil.png',
    '/uploads/knowledgebase/%5cevil.png',
    '/assets/knowledgebase/screenshots/tenant/../secret.png',
    '/assets/knowledgebase/screenshots/tenant/%2e%2e/secret.png',
    '/assets/knowledgebase/screenshots/tenant/%5cevil.png',
    'java&#x73;cript:alert(1)',
    'java%73cript:alert(1)',
    'data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;',
];
foreach ($badUrls as $index => $url) {
    $tag = $index < 3 || $index >= 8
        ? '<a href="' . $url . '">bad</a>'
        : '<img src="' . $url . '" alt="bad">';
    $result = $sanitizer->sanitize($tag);
    assertSanitizerNotContains($index < 3 || $index >= 8 ? 'href=' : 'src=', $result, 'rejected URL ' . $url);
}

$wrongImagePaths = $sanitizer->sanitize(
    '<img src="/uploads/other/guide.png" alt="wrong">'
    . '<img src="mailto:help@example.com" alt="mail">'
);
assertSanitizerNotContains('src=', $wrongImagePaths, 'image source positive allowlist');

echo "ok\n";
