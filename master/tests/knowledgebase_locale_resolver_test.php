<?php
declare(strict_types=1);

use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\LocaleResolver;

require_once __DIR__ . '/../src/Http/Request.php';

$resolverPath = __DIR__ . '/../src/Support/LocaleResolver.php';
if (!file_exists($resolverPath)) {
    fwrite(STDERR, "missing LocaleResolver.php\n");
    exit(1);
}
require_once $resolverPath;

function assertLocale(string $expected, array $query = [], array $headers = [], string $label = ''): void
{
    $request = new Request('GET', '/knowledgebase', $query, [], $headers, '');
    $actual = LocaleResolver::resolve($request);
    if ($actual !== $expected) {
        fwrite(STDERR, ($label !== '' ? $label . ': ' : '') . 'expected locale ' . $expected . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

assertLocale('en', label: 'default');
assertLocale('zh-Hans', [], ['Cookie' => 'leikwan_locale=zh-Hans'], 'supported cookie');
assertLocale('zh-TW', [], ['Cookie' => 'leikwan_locale=zh%2DTW'], 'raw URL-decoded cookie');
assertLocale('zh-Hans', [], ['Cookie' => 'other=a=b=c; leikwan_locale=zh-Hans; x=2'], 'unrelated cookie may contain equals');
assertLocale('en', [], ['Cookie' => 'leikwan_locale=zh%2DTW=trailing'], 'split target cookie only on first equals');
assertLocale('zh-TW', ['lang' => 'zh-TW'], ['Cookie' => 'leikwan_locale=en'], 'query wins');
assertLocale('en', ['lang' => 'xx'], ['Cookie' => 'leikwan_locale=zh-TW'], 'invalid query forces English');
assertLocale('en', ['lang' => ['zh-TW']], ['Cookie' => 'leikwan_locale=zh-TW'], 'non-scalar query forces English');
assertLocale('en', [], ['Cookie' => 'leikwan_locale=xx'], 'invalid cookie');
assertLocale('en', [], ['Cookie' => 'leikwan_locale=%E0%A4%A'], 'malformed encoded cookie');
assertLocale('en', [], ['Cookie' => 'leikwan_locale='], 'missing cookie value');
assertLocale('en', [], ['Cookie' => 'leikwan_locale'], 'cookie without equals');

echo "ok\n";
