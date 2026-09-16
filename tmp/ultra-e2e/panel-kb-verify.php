<?php
declare(strict_types=1);
$root = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3';
require $root . '/master/vendor/autoload.php';
$config = new \Leikwan\Master\Config($root . '/master/.env');
$db = new \Leikwan\Master\Database\Database($config);
$service = new \Leikwan\Master\Support\KnowledgebaseService($db);
foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    $topic = $service->findPublishedTopicByKey('no-gui-clients', $locale);
    if ($topic === null) {
        echo $locale, ': MISSING', PHP_EOL;
        continue;
    }
    printf("%s: %s | chars=%d | published=%s%s", $locale, (string) $topic['title'], strlen((string) $topic['body_html']), (string) ($topic['status'] ?? '?'), PHP_EOL);
}
$topics = $service->listPublishedTopics('zh-Hans');
echo 'published topics (zh-Hans): ', count($topics), PHP_EOL;
$last = end($topics);
echo 'last topic key: ', (string) ($last['topic_key'] ?? '?'), PHP_EOL;
echo 'knowledgebase route check: /knowledgebase?thread=no-gui-clients', PHP_EOL;
