<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    // Minimal stub: rendering the OpenWrt card reads no live data.
    final class Database
    {
        public function fetchOne(string $sql, array $params = []): ?array { return null; }
        public function fetchAll(string $sql, array $params = []): array { return []; }
        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $data): int { return 0; }
        public function transaction(callable $callback): mixed { return $callback($this); }
    }
}

namespace {
    // Render the real tenantOpenwrtSection and assert the apk card is present
    // alongside the opkg card, with the right commands per edition.
    // Modelled on docker_install_surface_hidden_test.php's isolated-fixture
    // approach, but drives the real method through reflection.

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (str_starts_with($class, $prefix)) {
            $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\TenantController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function failOpenwrtCard(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $config = new Config(__DIR__ . '/missing.env');
    $db = new Database();
    $controller = new TenantController($config, $db, new ProvisioningService($config, $db));

    $render = Closure::bind(
        static function (TenantController $c, array $client) {
            $m = new ReflectionMethod($c, 'tenantOpenwrtSection');
            $m->setAccessible(true);
            return $m->invoke($c, $client);
        },
        null,
        TenantController::class
    );

    // With a client identity: both editions must render with install commands.
    // The command blocks are HTML-escaped, so && renders as &amp;&amp;.
    $html = $render($controller, ['id' => 7, 'agent_token' => 'tok-123']);
    foreach ([
        'cd /tmp &amp;&amp; { command -v curl &gt;/dev/null &amp;&amp; curl -fsSL' => 'apk download stage (apk cannot take HTTPS URLs directly)',
        '|| wget -q -O ' => 'wget fallback for images without curl',
        "-o &#039;leikwand-1.2.6-r1.apk&#039; " => 'each file is saved under its own name',
        "-o &#039;leikwan-runtime-1.2.6-r1.apk&#039; " => 'runtime is saved under its own name',
        "-o &#039;luci-app-leikwan-1.1.1-r1.apk&#039; " => 'luci app is saved under its own name',
        'apk add --allow-untrusted' => 'apk install command',
        '/apk/amd64/leikwand-1.2.6-r1.apk' => 'amd64 apk file URL',
        '/apk/arm64/leikwand-1.2.6-r1.apk' => 'arm64 apk file URL',
        'apk add --allow-untrusted &#039;leikwand-1.2.6-r1.apk&#039;' => 'apk installs the local file names, not URLs',
        'opkg update &amp;&amp; opkg install' => 'opkg install command',
        '/amd64/leikwand_1.2.6-r1_x86_64.ipk' => 'amd64 ipk file URL',
        '/arm64/leikwand_1.2.6-r1_aarch64_generic.ipk' => 'arm64 ipk file URL',
        'leikwand ctl register client_id=7' => 'register command carrying the client identity',
        'OpenWrt 21.02 / 22.03' => 'legacy card retained',
    ] as $needle => $why) {
        if (!str_contains($html, $needle)) {
            failOpenwrtCard("missing $why: $needle");
        }
    }
    // Both apk architecture variants must carry the runtime and LuCI packages too.
    foreach (['/apk/amd64/leikwan-runtime-1.2.6-r1.apk', '/apk/arm64/leikwan-runtime-1.2.6-r1.apk',
        '/apk/amd64/luci-app-leikwan-1.1.1-r1.apk', '/apk/arm64/luci-app-leikwan-1.1.1-r1.apk'] as $needle) {
        if (!str_contains($html, $needle)) {
            failOpenwrtCard("missing package URL: $needle");
        }
    }

    // The sections are ordered newest firmware first: 25.12+ apk, then
    // 23.05-24.10 opkg, then the 21.02/22.03 legacy CLI-only line.
    $order = [
        'OpenWrt 25.12+（apk 包管理器）· x86_64',
        'OpenWrt 25.12+（apk 包管理器）· arm64',
        'OpenWrt 23.05 – 24.10，opkg）</summary><pre class="tenant-client-install__command">' . 'opkg',
        'OpenWrt 21.02 / 22.03',
    ];
    $lastPos = -1;
    foreach ($order as $marker) {
        $pos = strpos($html, $marker);
        if ($pos === false) {
            failOpenwrtCard("section marker not found: $marker");
        }
        if ($pos < $lastPos) {
            failOpenwrtCard("sections must render newest firmware first: $marker appears out of order");
        }
        $lastPos = $pos;
    }

    // Without a client identity: directory URLs only, no register suffix.
    $anon = $render($controller, ['id' => 0, 'agent_token' => '']);
    if (str_contains($anon, 'leikwand ctl register')) {
        failOpenwrtCard('the anonymous card must not embed a register command');
    }
    if (!str_contains($anon, '/apk/amd64/') || !str_contains($anon, '/apk/arm64/')) {
        failOpenwrtCard('the anonymous card must still point at both apk directories');
    }

    echo "ok\n";
}
