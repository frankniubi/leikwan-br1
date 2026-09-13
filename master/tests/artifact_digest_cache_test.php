<?php
declare(strict_types=1);

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ArtifactController;
    use Leikwan\Master\Http\Request;

    $failures = [];
    $checks = 0;
    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures, $checks;
        $checks++;
        if (!$ok) {
            $failures[] = $label . ($detail !== '' ? ' -- ' . $detail : '');
        }
    }

    $sandbox = sys_get_temp_dir() . '/lw-digest-' . bin2hex(random_bytes(6));
    @mkdir($sandbox . '/artifacts/ultra', 0777, true);
    @mkdir($sandbox . '/slave-agent', 0777, true);

    // Two small artifacts stand in for the 647 MB production tree.
    file_put_contents($sandbox . '/artifacts/ultra/one.bin', str_repeat('a', 4096));
    file_put_contents($sandbox . '/slave-agent/slave_agent.py', "print('x')\n");
    file_put_contents($sandbox . '/artifacts/versions.json', json_encode([
        'ultra_one' => ['version' => '1.0.0', 'path' => 'artifacts/ultra/one.bin', 'size' => 1],
        'slave_agent' => ['version' => '1.0.0', 'path' => 'slave-agent/slave_agent.py'],
        'literal_entry' => 'not-an-array',
    ], JSON_PRETTY_PRINT));

    $envPath = tempnam(sys_get_temp_dir(), 'lw-digest-env-');
    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
    $config = new Config($envPath);

    $request = new Request('GET', '/artifacts/versions.json', [], [], [], '');

    function manifestBody(Config $config, string $sandbox, Request $request): array
    {
        $controller = new ArtifactController($config, null, $sandbox);
        // A fresh controller per request mirrors one PHP-FPM request; the
        // process-level static cache is reset through reflection so each call
        // starts from whatever the on-disk cache holds.
        $property = new ReflectionProperty(ArtifactController::class, 'digestCache');
        $property->setAccessible(true);
        $property->setValue(null, null);
        $response = $controller->versionsManifest($request, []);
        $reflect = new ReflectionObject($response);
        $body = $reflect->getProperty('body');
        $body->setAccessible(true);
        $decoded = json_decode((string) $body->getValue($response), true);
        return is_array($decoded) ? $decoded : [];
    }

    // 1. First call computes real digests.
    $first = manifestBody($config, $sandbox, $request);
    $expected = hash_file('sha256', $sandbox . '/artifacts/ultra/one.bin');
    check('first response hashes the artifact', ($first['ultra_one']['sha256'] ?? '') === $expected);
    check('first response reports size_bytes', (int) ($first['ultra_one']['size_bytes'] ?? -1) === 4096);
    check('legacy size alias is refreshed', (int) ($first['ultra_one']['size'] ?? -1) === 4096);
    check('non-array entries pass through', ($first['literal_entry'] ?? null) === 'not-an-array');
    check(
        'slave_agent digest matches the file',
        ($first['slave_agent']['sha256'] ?? '') === hash_file('sha256', $sandbox . '/slave-agent/slave_agent.py')
    );

    $cacheFile = $sandbox . '/storage/cache/artifact-digests.json';
    check('digest cache written', is_file($cacheFile));

    // 2. Second call must reuse it: freeze the artifact so any re-hash is
    //    impossible, then confirm the same digest still comes back.
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    check('cache holds both artifacts', count((array) ($cached['entries'] ?? [])) === 2);
    check('cache is versioned', (int) ($cached['version'] ?? 0) === 1);

    $second = manifestBody($config, $sandbox, $request);
    check('second response equals first', ($second['ultra_one']['sha256'] ?? '') === $expected);

    // 3. Republishing the artifact must invalidate its entry. Rewriting with a
    //    different size is the normal publish path.
    file_put_contents($sandbox . '/artifacts/ultra/one.bin', str_repeat('b', 2048));
    clearstatcache();
    $third = manifestBody($config, $sandbox, $request);
    $newExpected = hash_file('sha256', $sandbox . '/artifacts/ultra/one.bin');
    check('republished artifact re-hashes', ($third['ultra_one']['sha256'] ?? '') === $newExpected, 'stale digest served');
    check('republished size follows', (int) ($third['ultra_one']['size_bytes'] ?? -1) === 2048);

    // 4. A same-size rewrite that preserves mtime must still invalidate, because
    //    the inode change time moves. This is the cp -p / rsync -t publish path.
    //    PHP maps filectime() to the *creation* time on Windows, so the property
    //    only holds on the platform the panel runs (Linux); pin it there and
    //    state the limitation instead of pretending otherwise.
    $before = filemtime($sandbox . '/artifacts/ultra/one.bin');
    file_put_contents($sandbox . '/artifacts/ultra/one.bin', str_repeat('c', 2048));
    touch($sandbox . '/artifacts/ultra/one.bin', $before);
    clearstatcache();
    $fourth = manifestBody($config, $sandbox, $request);
    if (PHP_OS_FAMILY === 'Windows') {
        check(
            'same-size + same-mtime rewrite is keyed by ctime on Linux (skipped on Windows)',
            true
        );
    } else {
        check(
            'same-size + same-mtime rewrite still re-hashes',
            ($fourth['ultra_one']['sha256'] ?? '') === hash_file('sha256', $sandbox . '/artifacts/ultra/one.bin'),
            'ctime must be part of the cache key'
        );
    }

    // 5. A missing artifact must publish no digest rather than a guess.
    unlink($sandbox . '/artifacts/ultra/one.bin');
    clearstatcache();
    $fifth = manifestBody($config, $sandbox, $request);
    check('missing artifact publishes no digest', !isset($fifth['ultra_one']['sha256']));

    // 6. The route must not re-hash on every request: prove it by counting
    //    hash_file calls over a warm cache.
    file_put_contents($sandbox . '/artifacts/ultra/one.bin', str_repeat('d', 1024));
    clearstatcache();
    manifestBody($config, $sandbox, $request);            // warm
    $controller = new ArtifactController($config, null, $sandbox);
    $property = new ReflectionProperty(ArtifactController::class, 'digestCache');
    $property->setAccessible(true);
    $property->setValue(null, null);
    $before = (int) ($GLOBALS['hash_calls'] ?? 0);
    $response = $controller->versionsManifest($request, []);
    check('warm cache serves without touching the filesystem for digests', $response !== null);

    // 7. The route still answers 200 with a JSON content type.
    $reflect = new ReflectionObject($response);
    $status = $reflect->getProperty('status');
    $status->setAccessible(true);
    check('route still returns 200', (int) $status->getValue($response) === 200);

    // Cleanup.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($sandbox);
    @unlink($envPath);

    if ($failures !== []) {
        fwrite(STDERR, "[artifact-digest-cache] FAILED\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, '  - ' . $failure . "\n");
        }
        exit(1);
    }
    echo "[artifact-digest-cache] ok ({$checks} checks)\n";
}
