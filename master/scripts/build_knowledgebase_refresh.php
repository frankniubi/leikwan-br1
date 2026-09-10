<?php
declare(strict_types=1);

const REFRESH_TOPICS = [
    'getting-started-five-minute-overview',
    'install-client',
    'getting-started-first-forward-checklist',
    'connect-and-verify',
    'connection-modes-single-race-bond',
    'bond-sidecar-setup',
    'troubleshooting-common-mistakes',
];

/** @return never */
function failRefresh(string $message): never
{
    fwrite(STDERR, 'knowledgebase refresh: ' . $message . PHP_EOL);
    exit(1);
}

function refreshSqlString(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * These hashes identify only the built-in bodies present before the screenshot
 * guide release. A tenant article edited in the admin UI will not match them.
 *
 * @return array<string, array<string, string>>
 */
function legacyBodyHashes(): array
{
    return [
        'en' => [
            'getting-started-five-minute-overview' => '5a9d1cf71a3f498b422e861112411cc3a06228df340b8cd0183979e910af626d',
            'install-client' => 'fb08344920254cfcda2838d7529cc909a26d1f70692bdc08517d6f14a5ed1204',
            'getting-started-first-forward-checklist' => '4ac31556c0d61cf6ffa959bf99b9e166e9a9bf1232d2d3bdfc31a724cf1582d4',
            'connect-and-verify' => '53b4d27e69c5f7acf1efec30bc0339aceece3b951f3ff64b33843bdd2241a48e',
            'connection-modes-single-race-bond' => 'ac4ec7d678824ac8c31534849c2a0b9a886bbd1c50b8ebdcb56877359d04946e',
            'bond-sidecar-setup' => '952b0ac65df14b6d954f106a067e80a09b5f3d6c412934b87f826b5627af4132',
            'troubleshooting-common-mistakes' => '335fcc4fc84bb6f26a2353e6615a73033901f520a81dc88149151e6a9439f02a',
        ],
        'zh-Hans' => [
            'getting-started-five-minute-overview' => 'e90df395261a131b49e5e401fef61ad1896617f0faeba5bd8c28327b8e0eb933',
            'install-client' => 'f3aa04a602a3faa3084ec87ad726680ef4b19e1595bf408359a76eb3b259b1a4',
            'getting-started-first-forward-checklist' => '2f29dd98a42d184e4b35e7020cf58d0fb5e17075dbb4531a58f6cec97631e5c6',
            'connect-and-verify' => '43571b3081556524ad3e4c3b9e0f0c2cc01adc88e20b728968beea378e1135b9',
            'connection-modes-single-race-bond' => '2aa87344e6731b36df3090def47ae9d2029ca58c14112827bc894a4ba5c066bb',
            'bond-sidecar-setup' => 'b41cb1289f0ca9f87d84918d508ae28233c2fc44b066ede338e3db8577c3a225',
            'troubleshooting-common-mistakes' => '353cf08132cab9bfbbcce50de61ceb970ed62692e661697b1e8027ff444fbeae',
        ],
        'zh-TW' => [
            'getting-started-five-minute-overview' => 'b8141a6034ae77cee9d009c967e15bb69f5445735a2b5e9abeccc86ecb703b1e',
            'install-client' => 'd1284b44944cf7630fa648e480a5088a2fa1ed10e70719b93e83352ce9603906',
            'getting-started-first-forward-checklist' => 'aa65e9e6dd9c936041ac0ee7176ad2fe6d49fd25773153f47dd7a59c3e84ad71',
            'connect-and-verify' => '8f0d5c65c92cbe06955b2a1644324e695714eb06b91eb87e62b7f160aabbe6a3',
            'connection-modes-single-race-bond' => '45a5c457d24fc43393edd484ae7e92e1885d5ccd25c06e05eb09655ed6715f9f',
            'bond-sidecar-setup' => '1c14601ce0ac93b97834b53fc8e7e23e9ca68052845aefc7dbfa579e94bf0189',
            'troubleshooting-common-mistakes' => '186cfa2a63f2acb9dcc40790c53c4c5cdd2f922ec4682a04cd83c55150188962',
        ],
    ];
}

$root = dirname(__DIR__);
$manifest = require $root . '/resources/knowledgebase/topics.php';
$hashes = legacyBodyHashes();
$locales = ['en', 'zh-Hans', 'zh-TW'];
$sql = [
    '-- Guarded tenant guide refresh. Only untouched built-in bodies match the SHA-256 predicates below.',
];

foreach ($locales as $locale) {
    foreach (REFRESH_TOPICS as $topicKey) {
        $article = $manifest[$topicKey]['articles'][$locale] ?? null;
        if (!is_array($article)) {
            failRefresh('missing manifest article: ' . $locale . '/' . $topicKey);
        }

        $bodyPath = $root . '/resources/knowledgebase/' . (string) $article['body'];
        $body = file_get_contents($bodyPath);
        if (!is_string($body) || $body === '') {
            failRefresh('missing body: ' . $bodyPath);
        }

        $oldHash = $hashes[$locale][$topicKey] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $oldHash)) {
            failRefresh('invalid legacy hash: ' . $locale . '/' . $topicKey);
        }

        $sql[] = sprintf(
            "UPDATE knowledgebase_articles\n"
                . "SET title = %s,\n"
                . "    excerpt = %s,\n"
                . "    body_html = %s,\n"
                . "    status = 'published',\n"
                . "    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),\n"
                . "    updated_at = CURRENT_TIMESTAMP\n"
                . "WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = %s)\n"
                . "  AND locale = %s\n"
                . "  AND SHA2(body_html, 256) = %s;",
            refreshSqlString((string) $article['title']),
            refreshSqlString((string) $article['excerpt']),
            refreshSqlString($body),
            refreshSqlString($topicKey),
            refreshSqlString($locale),
            refreshSqlString($oldHash),
        );
    }
}

$outputPath = $root . '/migrations/20260806_refresh_tenant_user_guides.sql';
$output = implode("\n\n", $sql) . "\n";
if (file_put_contents($outputPath, $output, LOCK_EX) !== strlen($output)) {
    failRefresh('failed to write ' . $outputPath);
}

fwrite(STDOUT, $outputPath . PHP_EOL);
