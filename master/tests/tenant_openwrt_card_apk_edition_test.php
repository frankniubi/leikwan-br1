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
    // The one-line installer comes first and carries the client identity; the
    // per-version commands are the manual fallback after it.
    $universal = "sh /tmp/leikwan-install.sh --panel &#039;";
    foreach ([
        '/artifacts/install-openwrt.sh' => 'installer URL',
        'wget -qO /tmp/leikwan-install.sh' => 'wget download of the installer',
        '|| curl -fsSL -o /tmp/leikwan-install.sh' => 'curl fallback for the installer',
        $universal => 'installer run with the panel address',
        "--client-id 7 --agent-token &#039;tok-123&#039;" => 'installer registers the client',
        '手动安装' => 'manual fallback section',
    ] as $needle => $why) {
        if (!str_contains($html, $needle)) {
            failOpenwrtCard("missing $why: $needle");
        }
    }
    if (strpos($html, 'data-openwrt-universal') > strpos($html, 'OpenWrt 25.12+（apk 包管理器）')) {
        failOpenwrtCard('the one-line installer must render before the per-version commands');
    }
    // Collapsed like the Linux / Windows / macOS cards: between the card title
    // and the first <details> there is no command, only the title.
    $cardStart = strpos($html, 'data-openwrt-install');
    $firstDetails = strpos($html, '<details', (int) $cardStart);
    if ($cardStart === false || $firstDetails === false
        || str_contains(substr($html, $cardStart, $firstDetails - $cardStart), 'tenant-client-install__command')) {
        failOpenwrtCard('the OpenWrt card must keep every command inside a collapsed <details>');
    }
    foreach (['<summary>一键安装命令 · 复制即用</summary><pre class="tenant-client-install__command" data-openwrt-universal>',
        '<summary>查看版本 · 自动识别固件与架构</summary><pre class="tenant-client-install__command" data-openwrt-versions>'] as $needle) {
        if (!str_contains($html, $needle)) {
            failOpenwrtCard("collapsed section missing: $needle");
        }
    }
    if (str_contains($html, '<details open')) {
        failOpenwrtCard('the OpenWrt sections must start closed');
    }
    // The version report sits next to the installer and needs no credentials:
    // it is the same answer on every router, whatever the firmware. The
    // anonymous-card assertions live with the other $anon checks below.
    foreach ([
        'data-openwrt-versions' => 'version report block',
        '/artifacts/openwrt-versions.sh' => 'version report URL',
        'leikwan-versions.sh --panel' => 'version report run with the panel address',
        '--check' => 'the version report documents --check',
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
    if (!str_contains($anon, $universal) || str_contains($anon, '--client-id')) {
        failOpenwrtCard('the anonymous card must offer the installer without credentials');
    }
    if (!str_contains($anon, '/artifacts/openwrt-versions.sh') || !str_contains($anon, 'leikwan-versions.sh --panel')) {
        failOpenwrtCard('the anonymous card must offer the version report too');
    }

    // The installer and its manifest are served by the panel, uncached.
    $bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
    foreach (['/artifacts/install-openwrt.sh', '/artifacts/openwrt-packages.txt'] as $route) {
        if (!str_contains($bootstrap, "'" . $route . "'")) {
            failOpenwrtCard("route missing: $route");
        }
    }
    // The version report is served the same way, so a router can run it with
    // the panel URL it already knows.
    if (!str_contains($bootstrap, "'/artifacts/openwrt-versions.sh'")) {
        failOpenwrtCard('route missing: /artifacts/openwrt-versions.sh');
    }
    $versionsController = (string) file_get_contents(__DIR__ . '/../src/Controllers/ArtifactController.php');
    if (!str_contains($versionsController, "'install/openwrt-versions.sh'")) {
        failOpenwrtCard('the versions route does not serve install/openwrt-versions.sh');
    }
    if (!is_file(__DIR__ . '/../../install/openwrt-versions.sh')) {
        failOpenwrtCard('install/openwrt-versions.sh is missing from the tree the route serves');
    }
    // Every format x architecture x package has exactly one manifest entry,
    // which is what the installer requires.
    $manifest = (string) file_get_contents(__DIR__ . '/../../install/openwrt-packages.txt');
    $entries = [];
    foreach (preg_split('/\R/', $manifest) as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        if (preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $m) !== 1) {
            failOpenwrtCard("malformed manifest line: $line");
        }
        $entries[] = $m[2];
    }
    foreach (['amd64/', 'arm64/', 'apk/amd64/', 'apk/arm64/'] as $prefix) {
        $sep = str_starts_with($prefix, 'apk/') ? '-' : '_';
        foreach (['leikwand', 'leikwan-runtime', 'luci-app-leikwan'] as $name) {
            $hits = array_filter($entries, static fn(string $p): bool =>
                str_starts_with($p, $prefix) && !str_contains(substr($p, strlen($prefix)), '/')
                && preg_match('/^' . preg_quote($name . $sep, '/') . '\d/', substr($p, strlen($prefix))) === 1);
            if (count($hits) !== 1) {
                failOpenwrtCard("manifest needs exactly one $prefix$name entry, found " . count($hits));
            }
        }
    }

    echo "ok\n";
}
