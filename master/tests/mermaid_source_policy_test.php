<?php
declare(strict_types=1);

use Leikwan\Master\Support\MermaidSourcePolicy;

require_once __DIR__ . '/../src/Support/MermaidSourcePolicy.php';

function failMermaid(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertMermaidSame(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        failMermaid($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectMermaidInvalid(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    failMermaid($label . ': expected InvalidArgumentException');
}

$safeSource = "flowchart TD\naccTitle: Setup flow\naccDescr: Setup moves from plan to verification.\nA[Plan] --> B[Verify]";
$safe = '<pre class="mermaid">' . $safeSource . '</pre>';
assertMermaidSame([$safeSource], MermaidSourcePolicy::extract($safe), 'extract safe source');
assertMermaidSame(hash('sha256', $safeSource), MermaidSourcePolicy::digest($safe), 'single source digest');
MermaidSourcePolicy::assertPreviewable($safe);
MermaidSourcePolicy::assertPublishable($safe, MermaidSourcePolicy::digest($safe));

$encoded = '<pre class="mermaid">flowchart TD' . "\r\n"
    . 'accTitle: Comparison flow' . "\r"
    . 'accDescr: Entity decoding stays identical in PHP and the browser.' . "\n"
    . 'A["x &lt; y &amp; z &gt; q"] --&gt; B[Done]</pre>';
$encodedExpected = "flowchart TD\naccTitle: Comparison flow\naccDescr: Entity decoding stays identical in PHP and the browser.\nA[\"x < y & z > q\"] --> B[Done]";
assertMermaidSame([$encodedExpected], MermaidSourcePolicy::extract($encoded), 'entities decoded once and line endings normalized');

$secondSource = "flowchart LR\naccTitle: Second flow\naccDescr: A second accessible diagram.\nA --> B";
$multiple = '<pre class="mermaid">' . $safeSource . '</pre><pre class="mermaid">' . $secondSource . '</pre>';
assertMermaidSame(hash('sha256', $safeSource . "\n---\n" . $secondSource), MermaidSourcePolicy::digest($multiple), 'multiple source digest separator');

$exactClassOnly = '<pre class="mermaid other">ignored</pre>'
    . '<pre class=" MERMAID ">ignored too</pre>'
    . '<pre class="  mermaid  ">  ' . $safeSource . "\r\n" . '  </pre>';
assertMermaidSame([$safeSource], MermaidSourcePolicy::extract($exactClassOnly), 'only exact normalized Mermaid class extracted');

MermaidSourcePolicy::assertPreviewable('<p>No diagrams</p>');
MermaidSourcePolicy::assertPublishable('<p>No diagrams</p>', '');
MermaidSourcePolicy::assertPublishable('<p>No diagrams</p>', 'NOT-A-DIGEST');

$invalidSources = [
    "flowchart TD\naccDescr: Missing title.\nA --> B" => 'missing accTitle',
    "flowchart TD\naccTitle: Missing description\nA --> B" => 'missing accDescr',
    "sequenceDiagram\naccTitle: Sequence\naccDescr: Not allowed.\nA->>B: Hi" => 'sequence diagram',
    "graph TD\naccTitle: Legacy\naccDescr: Legacy graph syntax.\nA --> B" => 'legacy graph',
    "flowchart BT\naccTitle: Wrong direction\naccDescr: Wrong direction.\nA --> B" => 'wrong direction',
    "flowchart TD\naccTitle: Click\naccDescr: Click is active.\nclick A href \"https://example.com\"" => 'click statement',
    "flowchart TD\naccTitle: Click\naccDescr: Click is active.\n   CLICK A href \"https://example.com\"" => 'leading whitespace case-variant click',
    "flowchart TD\naccTitle: Directive\naccDescr: Directive is active.\n%%{init: {'theme': 'dark'}}%%\nA --> B" => 'directive',
    "flowchart TD\naccTitle: JavaScript\naccDescr: JavaScript is active.\nA[javascript:alert(1)]" => 'javascript scheme',
    "flowchart TD\naccTitle: Script\naccDescr: Script text is active.\n&lt;script src=x&gt;" => 'entity encoded script',
    "flowchart TD\naccTitle: Iframe\naccDescr: Iframe text is active.\n&lt;iframe src=x&gt;" => 'entity encoded iframe',
];
foreach ($invalidSources as $source => $label) {
    $body = '<pre class="mermaid">' . $source . '</pre>';
    expectMermaidInvalid(static fn () => MermaidSourcePolicy::assertPreviewable($body), $label);
}

$tooLong = "flowchart TD\naccTitle: Long flow\naccDescr: This diagram exceeds the byte budget.\nA[" . str_repeat('x', 10_000) . ']';
expectMermaidInvalid(
    static fn () => MermaidSourcePolicy::assertPreviewable('<pre class="mermaid">' . $tooLong . '</pre>'),
    'more than 10000 bytes'
);

foreach (['', str_repeat('A', 64), str_repeat('a', 63), str_repeat('g', 64), hash('sha256', 'wrong')] as $digest) {
    expectMermaidInvalid(static fn () => MermaidSourcePolicy::assertPublishable($safe, $digest), 'invalid or incorrect digest ' . $digest);
}
MermaidSourcePolicy::assertPublishable($safe, '  ' . MermaidSourcePolicy::digest($safe) . "\r\n");

expectMermaidInvalid(
    static fn () => MermaidSourcePolicy::assertPublishable(
        '<pre class="mermaid">flowchart TD' . "\n" . 'accTitle: Bad preview' . "\n" . 'A --> B</pre>',
        hash('sha256', 'irrelevant')
    ),
    'publish validates preview policy before digest'
);

echo "ok\n";
