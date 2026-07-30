<?php
declare(strict_types=1);

use Leikwan\Master\Support\MermaidSourcePolicy;

require_once __DIR__ . '/../src/Support/MermaidSourcePolicy.php';

function failCatalog(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertCatalog(bool $condition, string $message): void
{
    if (!$condition) {
        failCatalog($message);
    }
}

function assertCatalogSame(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        failCatalog($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return array<int, DOMElement> */
function catalogElementsByTag(string $html, string $tagName): array
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div id="catalog-root">'
                . $html
                . '</div></body></html>',
            LIBXML_HTML_NODEFDTD
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    assertCatalog($loaded === true, 'article HTML must parse');

    $root = $document->getElementById('catalog-root');
    assertCatalog($root instanceof DOMElement, 'article HTML wrapper must parse');

    $elements = [];
    foreach ($root->getElementsByTagName($tagName) as $element) {
        if ($element instanceof DOMElement) {
            $elements[] = $element;
        }
    }
    return $elements;
}

$manifestPath = __DIR__ . '/../resources/knowledgebase/topics.php';
assertCatalog(is_file($manifestPath), 'knowledgebase topic manifest is missing');
$topics = require $manifestPath;
assertCatalog(is_array($topics), 'knowledgebase topic manifest must return an array');

$expectedLocales = ['en'];
$expectedHeadings = [
    'What you will accomplish',
    'Before you begin',
    'Steps',
    'How to know it worked',
    'If it did not work',
    'Related guides',
];
$expectedTopics = [
    'getting-started-five-minute-overview' => [
        'is_pinned' => 1,
        'sort_order' => 10,
        'en' => [
            'title' => 'Get Connected in Five Minutes',
            'excerpt' => 'Follow the shortest path from an active package to a verified connection.',
        ],
    ],
    'install-client' => [
        'is_pinned' => 0,
        'sort_order' => 20,
        'en' => [
            'title' => 'Install the Client',
            'excerpt' => 'Install and sign in on Windows, Android, or Linux, then confirm the client is online.',
        ],
    ],
    'getting-started-first-forward-checklist' => [
        'is_pinned' => 0,
        'sort_order' => 30,
        'en' => [
            'title' => 'Create Your First Forward',
            'excerpt' => 'Choose a route package, enter the destination, save, and identify the assigned local port.',
        ],
    ],
    'connect-and-verify' => [
        'is_pinned' => 0,
        'sort_order' => 40,
        'en' => [
            'title' => 'Connect and Verify',
            'excerpt' => 'Choose the correct client address, test the service, and confirm that the route is working.',
        ],
    ],
    'connection-modes-single-race-bond' => [
        'is_pinned' => 0,
        'sort_order' => 50,
        'en' => [
            'title' => 'Choose a Connection Mode',
            'excerpt' => 'Understand Single, Race, and Bond by the outcome you need rather than internal topology.',
        ],
    ],
    'bond-sidecar-setup' => [
        'is_pinned' => 0,
        'sort_order' => 60,
        'en' => [
            'title' => 'Configure a Sidecar',
            'excerpt' => 'Create and verify a Sidecar only when your Bond route package requires one.',
        ],
    ],
    'troubleshooting-common-mistakes' => [
        'is_pinned' => 0,
        'sort_order' => 70,
        'en' => [
            'title' => 'Troubleshoot Common Problems',
            'excerpt' => 'Start from the symptom you can see and follow the shortest safe recovery path.',
        ],
    ],
    'glossary' => [
        'is_pinned' => 0,
        'sort_order' => 80,
        'en' => [
            'title' => 'Glossary',
            'excerpt' => 'Plain-language definitions for the product and network terms used in the guides.',
        ],
    ],
];

assertCatalogSame(array_keys($expectedTopics), array_keys($topics), 'topic keys and order must be exact');
assertCatalogSame(8, count($topics), 'catalog must contain exactly eight topics');

$requiredArticleText = [
    'getting-started-five-minute-overview' => [
        'active package',
        'Create Forward',
        'assigned local port',
        'actual application protocol',
        'application request succeeds',
    ],
    'install-client' => [
        'Download Windows App',
        'Master URL',
        'Email',
        'Password',
        'Connection successful!',
        'Connected',
        'Running',
        'arm64',
        'Android 8.0+',
        'VPN',
        'Ready to connect',
        'Connecting...',
        'supported LeiGod service',
        'Linux Install',
        'Copy Install Command',
        'systemd',
        'Python 3',
        'root-equivalent',
        'bearer agent token',
        'installed leikwan-client-agent',
        'config_pull_state.json',
        'status',
        'ok',
    ],
    'getting-started-first-forward-checklist' => [
        'Forward Route',
        'Target Host',
        'Target Port',
        'Local Listen Port',
        'Transport',
        'TCP',
        'TCP + UDP',
        'UDP',
        'Save',
        'assigned local port',
        'provisioning',
        'recent client sync',
    ],
    'connect-and-verify' => [
        '127.0.0.1',
        'reachable Windows or Linux client-host address',
        'firewall',
        'security group',
        'NAT',
        'Android',
        'loopback-only',
        'actual application protocol',
        'target logs',
    ],
    'connection-modes-single-race-bond' => [
        'Single',
        'Race',
        'Bond',
        'not bandwidth aggregation',
        'one winner per connection',
        'fan out datagrams',
        'at least two usable endpoints',
        'differs by platform',
        'published route contract',
        'ask your administrator',
    ],
    'bond-sidecar-setup' => [
        'Sidecar',
        'intermediate target-side transport',
        'not the target application',
        'published route contract',
        'Target Endpoints',
        'Create Transport Endpoint',
        'Transport Listen Port',
        'Save Transport Endpoint',
        'Last Heartbeat',
        'backend listener',
        'heartbeat alone',
    ],
    'troubleshooting-common-mistakes' => [
        'recent sync',
        'correct address',
        'port is allowed',
        'forward and route',
        'target service from its own host',
        'View Diagnostics',
        'support',
        'random topology changes',
    ],
    'glossary' => [
        'Active package',
        'Forward',
        'Forward Route',
        'Target Host',
        'Target Port',
        'Local Listen Port',
        'Client',
        'Single',
        'Race',
        'Bond',
        'Sidecar',
        'Recent sync',
        'Running',
        'Application verification',
    ],
];

$forbiddenFirstForwardText = [
    'Connection Mode',
    'Forward Combination',
    'slave',
    'leg',
    'runtime',
];
$allTopicKeys = array_keys($expectedTopics);
$totalDiagrams = 0;

foreach ($topics as $topicKey => $topic) {
    assertCatalog(is_array($topic), $topicKey . ': topic entry must be an array');
    assertCatalogSame(
        ['is_pinned', 'sort_order', 'articles'],
        array_keys($topic),
        $topicKey . ': topic fields must be exact'
    );
    assertCatalogSame($expectedTopics[$topicKey]['is_pinned'], $topic['is_pinned'], $topicKey . ': pinned flag');
    assertCatalogSame($expectedTopics[$topicKey]['sort_order'], $topic['sort_order'], $topicKey . ': sort order');
    assertCatalog(is_array($topic['articles']), $topicKey . ': articles must be an array');

    foreach ($expectedLocales as $locale) {
        assertCatalog(isset($topic['articles'][$locale]) && is_array($topic['articles'][$locale]), $topicKey . ': missing ' . $locale . ' metadata');
        $article = $topic['articles'][$locale];
        assertCatalogSame(['title', 'excerpt', 'body'], array_keys($article), $topicKey . ': ' . $locale . ' metadata fields must be exact');
        assertCatalogSame($expectedTopics[$topicKey][$locale]['title'], $article['title'], $topicKey . ': ' . $locale . ' title');
        assertCatalogSame($expectedTopics[$topicKey][$locale]['excerpt'], $article['excerpt'], $topicKey . ': ' . $locale . ' excerpt');
        assertCatalogSame($locale . '/' . $topicKey . '.html', $article['body'], $topicKey . ': ' . $locale . ' body path');

        $bodyPath = __DIR__ . '/../resources/knowledgebase/' . $article['body'];
        assertCatalog(is_file($bodyPath) && is_readable($bodyPath), $topicKey . ': missing or unreadable ' . $locale . ' body file');
        $body = file_get_contents($bodyPath);
        assertCatalog(is_string($body) && trim($body) !== '', $topicKey . ': empty ' . $locale . ' body');
        assertCatalog(preg_match('//u', $body) === 1, $topicKey . ': body must be valid UTF-8');

        foreach (['English:', '中文：', '中文繁體：', '<script', 'onclick', '<iframe', 'cdn.jsdelivr.net', 'unpkg.com/mermaid', 'mermaid@'] as $forbidden) {
            assertCatalog(!str_contains(strtolower($body), strtolower($forbidden)), $topicKey . ': forbidden body text ' . $forbidden);
        }
        assertCatalog(preg_match('/\b(?:token|password|route key|private key|session material)\s*[:=]\s*\S+/i', strip_tags($body)) !== 1, $topicKey . ': body appears to expose a secret value');

        $h2Elements = catalogElementsByTag($body, 'h2');
        $headings = array_map(static fn (DOMElement $heading): string => trim($heading->textContent), $h2Elements);
        assertCatalogSame($expectedHeadings, $headings, $topicKey . ': h2 headings must appear exactly once and in order');

        assertCatalog(str_contains($body, '<ol>'), $topicKey . ': actions must be presented as short ordered steps');
        assertCatalog(str_contains($body, '<strong>Stop'), $topicKey . ': failure guidance must include a safe stop');
        foreach ($requiredArticleText[$topicKey] as $requiredText) {
            assertCatalog(str_contains($body, $requiredText), $topicKey . ': missing required beginner guidance: ' . $requiredText);
        }

        $diagrams = MermaidSourcePolicy::extract($body);
        $expectedDiagramCount = $topicKey === 'glossary' ? 0 : 1;
        assertCatalogSame($expectedDiagramCount, count($diagrams), $topicKey . ': Mermaid diagram count');
        MermaidSourcePolicy::assertPreviewable($body);
        $totalDiagrams += count($diagrams);

        if ($diagrams !== []) {
            $expectedDirection = $topicKey === 'bond-sidecar-setup' ? 'LR' : 'TD';
            assertCatalog(str_starts_with($diagrams[0], 'flowchart ' . $expectedDirection . "\n"), $topicKey . ': Mermaid direction');
            assertCatalog(preg_match('/^accTitle:\s*\S.+$/m', $diagrams[0]) === 1, $topicKey . ': Mermaid accTitle');
            assertCatalog(preg_match('/^accDescr:\s*\S.+$/m', $diagrams[0]) === 1, $topicKey . ': Mermaid accDescr');

            $preElements = catalogElementsByTag($body, 'pre');
            assertCatalogSame(1, count($preElements), $topicKey . ': must have one pre element');
            assertCatalogSame('mermaid', $preElements[0]->getAttribute('class'), $topicKey . ': Mermaid must use the exact trusted class');
            $next = $preElements[0]->nextSibling;
            while ($next !== null && !$next instanceof DOMElement) {
                $next = $next->nextSibling;
            }
            assertCatalog($next instanceof DOMElement && strtolower($next->tagName) === 'p' && trim($next->textContent) !== '', $topicKey . ': ordinary prose must immediately explain the diagram conclusion');
        }

        preg_match_all('/href="\/knowledgebase\?thread=([a-z0-9-]+)"/', $body, $matches);
        $linkedTopicKeys = $matches[1] ?? [];
        foreach ($linkedTopicKeys as $linkedTopicKey) {
            assertCatalog(in_array($linkedTopicKey, $allTopicKeys, true), $topicKey . ': unknown related guide link ' . $linkedTopicKey);
        }
        if ($topicKey !== 'glossary') {
            assertCatalog($linkedTopicKeys !== [], $topicKey . ': must link at least one related guide');
        }
    }
}

assertCatalogSame(7, $totalDiagrams, 'catalog must contain exactly seven Mermaid diagrams');

$firstForwardBody = file_get_contents(__DIR__ . '/../resources/knowledgebase/en/getting-started-first-forward-checklist.html');
assertCatalog(is_string($firstForwardBody), 'first Forward body must be readable');
foreach ($forbiddenFirstForwardText as $forbidden) {
    assertCatalog(preg_match('/\b' . preg_quote($forbidden, '/') . '\b/i', strip_tags($firstForwardBody)) !== 1, 'first Forward guide must not expose ' . $forbidden);
}

$generatorPath = __DIR__ . '/../scripts/build_knowledgebase_seed.php';
assertCatalog(is_file($generatorPath) && is_readable($generatorPath), 'knowledgebase seed generator is missing');
$generator = file_get_contents($generatorPath);
assertCatalog(is_string($generator) && trim($generator) !== '', 'knowledgebase seed generator is empty');
foreach ([
    "['en', 'zh-Hans', 'zh-TW']",
    'exactly 8 topics',
    'all required locale metadata and body files',
    '--output=',
    '20260729_seed_multilingual_knowledgebase.sql',
    'CURRENT_TIMESTAMP',
    'str_replace("\'", "\'\'", $value)',
    'INSERT INTO knowledgebase_topics',
    'INSERT INTO knowledgebase_articles',
    'FROM knowledgebase_threads AS legacy',
    'FROM knowledgebase_topics AS existing_topic',
    'FROM knowledgebase_articles AS existing_article',
    'WHERE NOT EXISTS',
    'topic.topic_key = legacy.slug',
    'legacy.is_pinned, legacy.sort_order, legacy.created_at, legacy.updated_at',
    "topic.id, 'en', legacy.author_user_id, legacy.title, legacy.excerpt, legacy.body_html",
    'legacy.status, legacy.published_at, legacy.created_at, legacy.updated_at',
    "existing_article.locale = 'en'",
    "legacy.slug NOT IN ('getting-started-first-forward-checklist', 'connection-modes-single-race-bond', 'bond-sidecar-setup', 'troubleshooting-common-mistakes')",
] as $requiredGeneratorText) {
    assertCatalog(str_contains($generator, $requiredGeneratorText), 'generator contract missing: ' . $requiredGeneratorText);
}
foreach (['UTC_TIMESTAMP', 'ON DUPLICATE KEY', 'ON CONFLICT', 'INSERT OR IGNORE', 'UPDATE knowledgebase_articles', 'DELETE FROM knowledgebase_threads', 'UPDATE knowledgebase_threads', 'DROP TABLE knowledgebase_threads', 'build timestamp'] as $forbiddenGeneratorText) {
    assertCatalog(!str_contains($generator, $forbiddenGeneratorText), 'generator contains forbidden SQL or nondeterminism: ' . $forbiddenGeneratorText);
}

assertCatalogSame(2, substr_count($generator, 'foreach ($requiredLocales as $locale)'), 'generator must validate and emit every required locale');
assertCatalogSame(24, 8 * 3, 'generator default article count contract');

echo "ok\n";
