<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/MainlandRegionPolicy.php';

use Leikwan\Master\Support\MainlandRegionPolicy;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        fail($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$policy = new MainlandRegionPolicy();
assertSame('mainland-adjacency-v1', $policy->artifactVersion(), 'artifact version');
assertTrue(preg_match('/^[a-f0-9]{64}$/', $policy->artifactSha256()) === 1, 'artifact content digest');
$all = $policy->allProvinces();
assertSame(31, count($all), 'Mainland province coverage');
assertSame(31, count(array_unique($all)), 'Mainland province uniqueness');

foreach ($all as $province) {
    assertTrue($policy->isMainlandProvince($province), 'province must be Mainland: ' . $province);
    $neighbors = $policy->directNeighbors($province);
    foreach ($neighbors as $neighbor) {
        assertTrue(in_array($province, $policy->directNeighbors($neighbor), true), 'adjacency must be symmetric: ' . $province . ' / ' . $neighbor);
        assertTrue($neighbor !== $province, 'province cannot neighbor itself: ' . $province);
    }
}

assertSame('SN', $policy->normalizeProvince('陕西省'), 'Chinese province normalization');
assertSame('SN', $policy->normalizeProvince('shaanxi'), 'English province normalization');
assertSame('BJ', $policy->normalizeProvince('北京市'), 'municipality normalization');
assertSame(null, $policy->normalizeProvince('香港'), 'Hong Kong is not Mainland');
assertSame('SN:渭南', $policy->normalizeCity('陕西', '渭南市'), 'city parent key normalization');
assertSame('SN:weinan', $policy->normalizeCity('SN', 'Weinan'), 'ASCII city key normalization');
assertSame(null, $policy->normalizeCity('香港', '九龙'), 'non-Mainland city rejection');

assertSame(['JS', 'SH', 'ZJ'], sorted($policy->allowedProvinces('SH')), 'Shanghai circle');
assertSame(['BJ', 'HE', 'TJ'], sorted($policy->allowedProvinces('BJ')), 'Beijing circle');
foreach (['XJ', 'XZ', 'NM', 'SD'] as $province) {
    assertSame([$province], $policy->allowedProvinces($province), 'self-only special province ' . $province);
}
assertSame(['HI'], $policy->allowedProvinces('HI'), 'Hainan has no land neighbor');
assertSame(['JS', 'SH', 'ZJ'], sorted($policy->allowedProvinces('JS')), 'Jiangsu uses exact Shanghai circle');
assertSame(['BJ', 'HE', 'TJ'], sorted($policy->allowedProvinces('HE')), 'Hebei uses exact Beijing circle');
assertSame(['CQ', 'GX', 'GZ', 'HN', 'SC', 'YN'], sorted($policy->allowedProvinces('GZ')), 'Guizhou ordinary adjacency');

function sorted(array $values): array
{
    sort($values);
    return $values;
}

echo "ok\n";
