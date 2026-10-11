<?php
declare(strict_types=1);

function failSeedRegression(string $message): never
{
    throw new RuntimeException('knowledgebase seed regression: ' . $message);
}

function assertSeedRegression(bool $condition, string $message): void
{
    if (!$condition) {
        failSeedRegression($message);
    }
}

function deleteSeedRegressionTemp(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}

$root = realpath(__DIR__ . '/..');
assertSeedRegression($root !== false, 'could not resolve master root');

$manifestPath = $root . '/resources/knowledgebase/topics.php';
$builderPath = $root . '/scripts/build_knowledgebase_seed.php';
$migrationPath = $root . '/migrations/20260729_seed_multilingual_knowledgebase.sql';
$tempOutput = tempnam(sys_get_temp_dir(), 'leikwan-kb-seed-');
assertSeedRegression($tempOutput !== false, 'could not allocate temporary output path');
deleteSeedRegressionTemp($tempOutput);

$failure = null;
try {
    assertSeedRegression(is_file($manifestPath), 'topic manifest is missing');
    $topics = require $manifestPath;
    assertSeedRegression(is_array($topics), 'topic manifest must return an array');
    assertSeedRegression(count($topics) === 7, 'manifest must define exactly seven topics');

    $locales = ['en', 'zh-Hans', 'zh-TW'];
    $articleCount = 0;
    foreach ($topics as $topicKey => $topic) {
        assertSeedRegression(is_string($topicKey) && $topicKey !== '', 'manifest topic key must be non-empty');
        assertSeedRegression(is_array($topic) && isset($topic['articles']) && is_array($topic['articles']), $topicKey . ': manifest articles are missing');
        assertSeedRegression(array_keys($topic['articles']) === $locales, $topicKey . ': manifest locales must be exact');
        $articleCount += count($topic['articles']);
    }
    assertSeedRegression($articleCount === 21, 'manifest must define exactly 21 localized articles');

    assertSeedRegression(is_file($builderPath), 'seed builder is missing');
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($builderPath)
        . ' --output=' . escapeshellarg($tempOutput);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    assertSeedRegression($exitCode === 0, 'seed builder failed: ' . implode(PHP_EOL, $output));
    $generated = file_get_contents($tempOutput);
    assertSeedRegression(is_string($generated) && $generated !== '', 'seed builder produced no output');

    assertSeedRegression(is_file($migrationPath), 'committed generated migration is missing');
    $committed = file_get_contents($migrationPath);
    assertSeedRegression(is_string($committed), 'committed generated migration is unreadable');
    assertSeedRegression($generated === $committed, 'committed generated migration is stale; regenerate it with the seed builder');

    $legacyKeys = [
        'getting-started-first-forward-checklist',
        'connection-modes-single-race-bond',
        'bond-sidecar-setup',
        'troubleshooting-common-mistakes',
    ];
    $legacyExclusion = "legacy.slug NOT IN ('" . implode("', '", $legacyKeys) . "')";
    assertSeedRegression(str_contains($committed, $legacyExclusion), 'legacy built-in key exclusion must be exact');
    assertSeedRegression(str_contains($committed, 'FROM knowledgebase_threads AS legacy'), 'custom legacy rows must migrate from legacy threads');
    assertSeedRegression(str_contains($committed, "topic.id, 'en', legacy.author_user_id, legacy.title, legacy.excerpt, legacy.body_html"), 'custom legacy rows must migrate as locale en');
    assertSeedRegression(str_contains($committed, "existing_article.locale = 'en'"), 'custom legacy migration must preserve an existing English article');
    assertSeedRegression(
        substr_count($committed, 'COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci') === 2,
        'legacy topic-key comparisons must normalize mixed MySQL collations'
    );

    foreach (['INSERT IGNORE', 'ON DUPLICATE KEY', 'REPLACE INTO'] as $forbidden) {
        assertSeedRegression(stripos($committed, $forbidden) === false, 'generated migration must not use ' . $forbidden);
    }
    assertSeedRegression(
        preg_match('/\bUPDATE\s+knowledgebase_articles\b/i', $committed) !== 1,
        'generated migration must not update knowledgebase articles'
    );

    $statements = preg_split('/;\s*(?:\R|$)/', trim($committed));
    assertSeedRegression(is_array($statements), 'could not split generated migration statements');
    $defaultTopicInserts = [];
    $defaultArticleInserts = [];
    foreach ($statements as $statement) {
        if (str_contains($statement, 'INSERT INTO knowledgebase_topics') && str_contains($statement, 'CURRENT_TIMESTAMP')) {
            $defaultTopicInserts[] = $statement;
        }
        if (str_contains($statement, 'INSERT INTO knowledgebase_articles') && str_contains($statement, 'FROM knowledgebase_topics AS topic')) {
            $defaultArticleInserts[] = $statement;
        }
    }

    assertSeedRegression(count($defaultTopicInserts) === 7, 'generated migration must contain exactly seven default topic inserts');
    foreach ($defaultTopicInserts as $statement) {
        assertSeedRegression(
            preg_match('/INSERT\s+INTO\s+knowledgebase_topics[\s\S]*SELECT[\s\S]*WHERE\s+NOT\s+EXISTS/is', $statement) === 1,
            'every default topic insert must be guarded INSERT INTO ... SELECT ... WHERE NOT EXISTS'
        );
    }

    assertSeedRegression(count($defaultArticleInserts) === 21, 'generated migration must contain exactly 21 guarded default article inserts');
    foreach ($defaultArticleInserts as $statement) {
        assertSeedRegression(
            preg_match('/INSERT\s+INTO\s+knowledgebase_articles[\s\S]*SELECT[\s\S]*WHERE[\s\S]*NOT\s+EXISTS/is', $statement) === 1,
            'every default article insert must be guarded INSERT INTO ... SELECT ... WHERE NOT EXISTS'
        );
    }

    foreach (array_keys($topics) as $topicKey) {
        assertSeedRegression(str_contains($committed, "'" . $topicKey . "'"), 'generated migration is missing topic key ' . $topicKey);
    }
    foreach ($locales as $locale) {
        assertSeedRegression(str_contains($committed, "'" . $locale . "'"), 'generated migration is missing locale ' . $locale);
    }

    foreach ([
        'Getting Started: First Forward Checklist / 第一條轉發檢查清單',
        'Connection Modes: Single, Race, Bond / 連線模式：Single、Race、Bond',
        'Bond Sidecar Setup / Bond Sidecar 設定',
        'Troubleshooting Common Mistakes / 常見錯誤排查',
        '<strong>中文繁體：</strong>',
        '如何使用轉發後的服務',
        '不要自行改 sidecar、隱藏路由或進階 runtime 假設',
    ] as $mixedLegacyPhrase) {
        assertSeedRegression(!str_contains($committed, $mixedLegacyPhrase), 'generated migration retains mixed-language legacy phrase ' . $mixedLegacyPhrase);
    }
} catch (Throwable $throwable) {
    $failure = $throwable;
} finally {
    deleteSeedRegressionTemp($tempOutput);
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "ok\n";
