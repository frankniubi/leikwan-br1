<?php
declare(strict_types=1);

function failCloudNodeTest(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertCloudNodeTest(bool $condition, string $message): void
{
    if (!$condition) {
        failCloudNodeTest($message);
    }
}

$root = realpath(__DIR__ . '/..');
assertCloudNodeTest($root !== false, 'could not resolve master root');

// Check topics.php has the new key
$topics = require $root . '/resources/knowledgebase/topics.php';
assertCloudNodeTest(
    isset($topics['cloud-nodes-and-forward-pairing']),
    'cloud-nodes-and-forward-pairing topic not found in topics.php'
);

// Check the topic has all required locales
$newTopic = $topics['cloud-nodes-and-forward-pairing'];
foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    assertCloudNodeTest(
        isset($newTopic['articles'][$locale]),
        'missing articles for locale: ' . $locale
    );
}

// Check HTML files exist and contain expected content
$htmlFiles = [
    'en' => $root . '/resources/knowledgebase/en/cloud-nodes-and-forward-pairing.html',
    'zh-Hans' => $root . '/resources/knowledgebase/zh-Hans/cloud-nodes-and-forward-pairing.html',
    'zh-TW' => $root . '/resources/knowledgebase/zh-TW/cloud-nodes-and-forward-pairing.html',
];

foreach ($htmlFiles as $locale => $path) {
    assertCloudNodeTest(
        is_file($path),
        'HTML file missing for ' . $locale . ': ' . $path
    );

    $content = file_get_contents($path);
    assertCloudNodeTest(
        is_string($content) && $content !== '',
        'HTML file is empty for ' . $locale
    );

    // Check for the worked example with 30522
    assertCloudNodeTest(
        str_contains($content, '30522'),
        'worked example missing port 30522 in ' . $locale
    );
}

// Check zh-Hans contains the key phrase
$zhHansContent = file_get_contents($root . '/resources/knowledgebase/zh-Hans/cloud-nodes-and-forward-pairing.html');
assertCloudNodeTest(
    str_contains($zhHansContent, '一一对应'),
    'zh-Hans does not contain key phrase 一一对应'
);

// Check EXPECTED_TOPIC_COUNT is 8
$seedScript = file_get_contents($root . '/scripts/build_knowledgebase_seed.php');
assertCloudNodeTest(
    str_contains($seedScript, 'const EXPECTED_TOPIC_COUNT = 8'),
    'EXPECTED_TOPIC_COUNT not updated to 8'
);

// Check error message was updated
assertCloudNodeTest(
    str_contains($seedScript, 'generation requires exactly 8 topics'),
    'error message not updated for topic count'
);

// Check the migration file exists
$migrationPath = $root . '/migrations/20261111_add_cloud_nodes_and_forward_pairing_guide.sql';
assertCloudNodeTest(
    is_file($migrationPath),
    'migration file missing: ' . $migrationPath
);

$migrationContent = file_get_contents($migrationPath);
assertCloudNodeTest(
    is_string($migrationContent) && $migrationContent !== '',
    'migration file is empty'
);

// Verify migration has the topic insert
assertCloudNodeTest(
    str_contains($migrationContent, 'cloud-nodes-and-forward-pairing'),
    'migration does not contain topic key'
);

// Verify migration has article inserts for all locales
foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    assertCloudNodeTest(
        preg_match('/' . preg_quote($locale) . '/', $migrationContent) > 0,
        'migration missing locale: ' . $locale
    );
}

echo "cloud_node_pairing_guide_test: OK\n";
