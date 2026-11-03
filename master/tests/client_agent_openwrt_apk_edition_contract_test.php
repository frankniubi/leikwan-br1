<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    // Minimal stub: the snapshot path this test exercises reads the client row,
    // forwards and artifact metadata, never live data, but the controller
    // constructor wires real services around the Database handle and they must
    // be able to hold it.
    final class Database
    {
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM clients WHERE id')) {
                return ['id' => (int) ($params['id'] ?? 1), 'name' => 'fixture', 'status' => 'active'];
            }
            return null;
        }
        public function fetchAll(string $sql, array $params = []): array { return []; }
        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $data): int { return 0; }
        public function transaction(callable $callback): mixed { return $callback($this); }
    }
}

namespace {
    // OpenWrt 25.12 replaced opkg with apk, and apk cannot install .ipk files.
    // The pull must therefore serve routers the update flavour their firmware
    // consumes: X-Device-Package-Manager: apk routes to the
    // openwrt_leikwand_apk_* manifest entries, anything else keeps the opkg
    // openwrt_leikwand_* entries. Both editions stay in the manifest because the
    // installed base spans 21.02 through 25.12.

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (str_starts_with($class, $prefix)) {
            $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    });

    function failOpenWrtApkEdition(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// --- Source contracts -------------------------------------------------------

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    failOpenWrtApkEdition('failed to read ClientAgentController');
}

// The flavour header is read, normalised, and only the exact apk value
// switches editions — anything else (older leikwand, typos, other callers)
// must fall back to the opkg edition the installed base runs.
if (!str_contains($source, "x-device-package-manager")) {
    failOpenWrtApkEdition('the pull must read X-Device-Package-Manager');
}
if (!str_contains($source, "\$packageFlavour !== 'apk'")) {
    failOpenWrtApkEdition('the flavour value must be gated to exactly apk');
}
if (!str_contains($source, "'openwrt_leikwand_apk_' . \$leikwandArch")) {
    failOpenWrtApkEdition('the apk edition must come from openwrt_leikwand_apk_* manifest keys');
}
// A missing apk manifest entry must degrade to the opkg edition, not blank
// the update payload: pre-1.2.7 panels serve routers with 25.12 firmware.
if (!str_contains($source, "\$apkEntry !== []")) {
    failOpenWrtApkEdition('an absent apk manifest entry must fall back to the opkg entry');
}

// --- Manifest contracts -----------------------------------------------------

$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../artifacts/versions.json'), true);
if (!is_array($manifest)) {
    failOpenWrtApkEdition('versions.json is unreadable');
}
foreach (['openwrt_leikwand_apk_x86_64', 'openwrt_leikwand_apk_aarch64_generic'] as $key) {
    if (!isset($manifest[$key])) {
        failOpenWrtApkEdition("$key is missing from versions.json");
    }
    $entry = $manifest[$key];
    if (($entry['format'] ?? '') !== 'openwrt-apk') {
        failOpenWrtApkEdition("$key must declare format openwrt-apk");
    }
    foreach (['artifact_url', 'path', 'sha256', 'version'] as $field) {
        if (!isset($entry[$field])) {
            failOpenWrtApkEdition("$key is missing $field");
        }
    }
    if (!str_ends_with((string) $entry['artifact_url'], '.apk')) {
        failOpenWrtApkEdition("$key artifact_url must point at an .apk file");
    }
}

// The leikwand side routes the downloaded file to the matching installer:
// .apk goes to apk add --allow-untrusted, .ipk stays on opkg.
$go = file_get_contents(__DIR__ . '/../../tools/leikwand/self_update.go');
if ($go === false) {
    failOpenWrtApkEdition('failed to read leikwand self_update.go');
}
foreach ([
    'func installerAvailability()',
    'func packageManagerFlavour() string',
    'apk add --allow-untrusted',
    'func selfUpdateIsApk(url string) bool',
] as $needle) {
    if (!str_contains($go, $needle)) {
        failOpenWrtApkEdition("leikwand self_update.go lost: $needle");
    }
}
$panelGo = file_get_contents(__DIR__ . '/../../tools/leikwand/panel.go');
if ($panelGo === false || !str_contains($panelGo, 'X-Device-Package-Manager')) {
    failOpenWrtApkEdition('leikwand must report its package-manager flavour to the panel');
}

// --- Behavioural contract ---------------------------------------------------

// stableConfigSnapshot picks the apk entry when the flavour says apk, keeps
// opkg when it does not, and degrades to opkg when the apk entry is absent.
// The manifest cache is per-instance; build a controller that sees the real
// versions.json through artifactMetadata.
$config = new Config(__DIR__ . '/missing.env');
$db = new Database();
$controller = new ClientAgentController($config, $db, new ProvisioningService($config, $db));

$probe = Closure::bind(
    static function (ClientAgentController $c, string $platform, string $flavour, string $arch) {
        // artifactMetadata caches per instance, so a fresh reflection call
        // per combination is fine: the cache holds the same file contents.
        $m = new ReflectionMethod($c, 'stableConfigSnapshot');
        $m->setAccessible(true);
        $out = $m->invoke($c, 1, null, ['id' => 1], $arch, $platform, $flavour);
        return is_array($out['client_agent'] ?? null) ? $out['client_agent'] : [];
    },
    null,
    ClientAgentController::class
);

$apkX64 = $probe($controller, 'openwrt', 'apk', 'amd64');
if (($apkX64['artifact_url'] ?? '') !== '/artifacts/files/openwrt/apk/amd64/leikwand-1.2.6-r1.apk') {
    failOpenWrtApkEdition('apk flavour + amd64 must serve the apk edition, got ' . json_encode($apkX64));
}
$apkArm = $probe($controller, 'openwrt', 'apk', 'arm64');
if (($apkArm['artifact_url'] ?? '') !== '/artifacts/files/openwrt/apk/arm64/leikwand-1.2.6-r1.apk') {
    failOpenWrtApkEdition('apk flavour + arm64 must serve the apk edition, got ' . json_encode($apkArm));
}
$opkg = $probe($controller, 'openwrt', '', 'amd64');
if (($opkg['artifact_url'] ?? '') !== '/artifacts/files/openwrt/amd64/leikwand_1.2.6-r1_x86_64.ipk') {
    failOpenWrtApkEdition('no flavour must keep the opkg edition, got ' . json_encode($opkg));
}
    $bogus = $probe($controller, 'openwrt', 'ipk', 'amd64');
    if (($bogus['artifact_url'] ?? '') !== ($opkg['artifact_url'] ?? '')) {
        failOpenWrtApkEdition('an unrecognised flavour must degrade to opkg');
    }

    echo "ok\n";
}
