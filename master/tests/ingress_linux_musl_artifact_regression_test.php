<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$artifactPath = dirname(__DIR__, 2) . '/artifacts/wstunnel/leikwan-ingress_linux_amd64';
$artifact = file_get_contents($artifactPath);
if (!is_string($artifact) || $artifact === '') {
    fail('failed to read Linux ingress artifact');
}

if (str_contains($artifact, '/lib64/ld-linux') || str_contains($artifact, 'GLIBC_')) {
    fail('Linux ingress artifact must be a musl static build without a GLIBC runtime dependency');
}

echo "ok\n";
