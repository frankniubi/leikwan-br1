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

function assertLocale(string $expected, array $query = [], array $headers = []): void
{
    $request = new Request('GET', '/knowledgebase', $query, [], $headers, '');
    $actual = LocaleResolver::resolve($request);
    if ($actual !== $expected) {
        fwrite(STDERR, 'expected locale ' . $expected . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

assertLocale('en');
assertLocale('zh-Hans', [], ['Cookie' => 'leikwan_locale=zh-Hans']);
assertLocale('zh-TW', ['lang' => 'zh-TW'], ['Cookie' => 'leikwan_locale=en']);
assertLocale('en', ['lang' => 'xx'], ['Cookie' => 'leikwan_locale=zh-TW']);
assertLocale('en', ['lang' => ['zh-TW']], ['Cookie' => 'leikwan_locale=zh-TW']);
assertLocale('zh-TW', [], ['Cookie' => 'other=1; leikwan_locale=zh-TW; x=2']);

echo "ok\n";
