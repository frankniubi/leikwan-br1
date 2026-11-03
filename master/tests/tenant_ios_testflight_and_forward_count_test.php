<?php
declare(strict_types=1);

// iOS TestFlight card + Apple ID record, and the forward counter on the
// overview page. The counter used to count soft-deleted rules, so every
// create/delete cycle raised it permanently.
//
// Part one runs AppleId against an in-memory Database double; part two greps
// the panel sources. Source checks run in a child process so the panel
// classes loaded below do not have to match the real ones.

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath) {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
    }
}

namespace Leikwan\Master\Support {
    final class ApiException extends \RuntimeException
    {
        /** @var array<string, mixed> */
        private array $details;

        /** @param array<string, mixed> $details */
        public function __construct(string $message, int $statusCode = 400, array $details = [])
        {
            parent::__construct($message, $statusCode);
            $this->details = $details;
        }

        public function statusCode(): int { return $this->getCode(); }
    }

    final class ServiceStatus
    {
        public const EXPIRED = 'expired';

        /** @param array<string, mixed> $service */
        public static function effective(array $service, ?int $now = null): string
        {
            $status = (string) ($service['status'] ?? '');
            if ($status !== 'active') {
                return $status;
            }
            $expiresAt = trim((string) ($service['expire_at'] ?? ''));
            if ($expiresAt === '') {
                return $status;
            }
            $timestamp = (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp();
            return $timestamp <= ($now ?? time()) ? self::EXPIRED : $status;
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, list<array<string, mixed>>> */
        private array $tables = [];
        /** @var list<array{0:string,1:array<string,mixed>}> */
        public array $executed = [];

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (!str_contains($sql, 'FROM users WHERE id = :id')) {
                return null;
            }
            foreach ($this->tables['users'] ?? [] as $row) {
                if ((int) $row['id'] === (int) ($params['id'] ?? 0)) {
                    return $row;
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $this->executed[] = [$sql, $params];
            if (!str_contains($sql, 'UPDATE users')) {
                return 0;
            }
            foreach ($this->tables['users'] as &$row) {
                if ((int) $row['id'] !== (int) ($params['id'] ?? 0)) {
                    continue;
                }
                // Mirrors the guarded UPDATE: only a NULL column is claimed.
                if (($row['apple_id'] ?? null) !== null) {
                    return 0;
                }
                $row['apple_id'] = $params['apple_id'];
                $row['apple_id_submitted_at'] = $params['submitted_at'];
                $row['updated_at'] = $params['updated_at'];
                return 1;
            }
            return 0;
        }
    }
}

namespace {
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\AppleId;

    require_once __DIR__ . '/../src/Support/AppleId.php';

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function check(bool $condition, string $label): void
    {
        if (!$condition) {
            fail($label);
        }
    }

    // --- Apple ID normalization --------------------------------------------

    check(AppleId::normalize('  Someone@Example.COM ') === 'someone@example.com', 'Apple ID is trimmed and lowercased');
    check(AppleId::normalize('first.last+tag@sub.example.co.uk') === 'first.last+tag@sub.example.co.uk', 'real Apple IDs with +tag and subdomains pass');
    check(AppleId::normalize('') === null, 'empty Apple ID is refused');
    check(AppleId::normalize('   ') === null, 'whitespace Apple ID is refused');
    check(AppleId::normalize('not-an-email') === null, 'Apple ID without @ is refused');
    check(AppleId::normalize('a@b') === null, 'Apple ID without a dotted domain is refused');
    check(AppleId::normalize('a@@b.com') === null, 'Apple ID with two @ is refused');
    check(AppleId::normalize('a b@c.com') === null, 'Apple ID with a space is refused');
    check(AppleId::normalize('13800138000@') === null, 'Apple ID without a domain is refused');
    check(AppleId::normalize(['nested@example.com']) === null, 'a non-string Apple ID is refused');
    check(AppleId::normalize(str_repeat('a', 190) . '@b.com') === null, 'Apple ID longer than the column is refused');

    // --- Membership ---------------------------------------------------------

    $future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s');
    $past = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');

    check(AppleId::hasValidMembership([['status' => 'active', 'expire_at' => $future]]) === true, 'an active package is valid membership');
    check(AppleId::hasValidMembership([['status' => 'active', 'expire_at' => $past]]) === false, 'a lapsed active-status package is not valid membership');
    check(AppleId::hasValidMembership([['status' => 'suspended', 'expire_at' => $future]]) === false, 'a suspended package is not valid membership');
    check(AppleId::hasValidMembership([['status' => 'expired', 'expire_at' => $future]]) === false, 'an expired package is not valid membership');
    check(AppleId::hasValidMembership([]) === false, 'no package is not valid membership');
    check(
        AppleId::hasValidMembership([
            ['status' => 'active', 'expire_at' => $past],
            ['status' => 'active', 'expire_at' => $future],
        ]) === true,
        'one live package among lapsed ones is valid membership'
    );

    // --- One Apple ID per account ------------------------------------------

    $db = new Database();
    $db->seed('users', [
        ['id' => 1, 'apple_id' => null, 'apple_id_submitted_at' => null],
        ['id' => 2, 'apple_id' => null, 'apple_id_submitted_at' => null],
    ]);

    $first = AppleId::claim($db, 1, 'Player@Apple.com');
    check($first === ['apple_id' => 'player@apple.com', 'created' => true], 'the first claim stores the normalized Apple ID');
    $stored = $db->fetchOne('SELECT apple_id FROM users WHERE id = :id', ['id' => 1]);
    check(($stored['apple_id'] ?? null) === 'player@apple.com', 'the Apple ID is written to users.apple_id');
    check(trim((string) ($stored['apple_id_submitted_at'] ?? '')) !== '', 'the submission time is recorded');

    $second = AppleId::claim($db, 1, 'other@apple.com');
    check($second === ['apple_id' => 'player@apple.com', 'created' => false], 'a second submission keeps the first Apple ID');
    check(
        ($db->fetchOne('SELECT apple_id FROM users WHERE id = :id', ['id' => 1])['apple_id'] ?? null) === 'player@apple.com',
        'the stored Apple ID is unchanged by a second submission'
    );

    $third = AppleId::claim($db, 2, 'someone@else.com');
    check($third['apple_id'] === 'someone@else.com', 'a different account stores its own Apple ID');

    try {
        AppleId::claim($db, 1, 'definitely not an email');
        fail('an invalid Apple ID must be refused');
    } catch (ApiException $exception) {
        check($exception->getMessage() === 'apple_id_invalid', 'an invalid Apple ID is refused with apple_id_invalid');
        check($exception->statusCode() === 422, 'the refusal is a 422');
    }
    check(
        ($db->fetchOne('SELECT apple_id FROM users WHERE id = :id', ['id' => 2])['apple_id'] ?? null) === 'someone@else.com',
        'a refused submission leaves the stored Apple ID alone'
    );

    // Only the first claim should have written; a rejected value must not
    // reach the database at all.
    $updates = array_values(array_filter($db->executed, static fn(array $entry): bool => str_contains($entry[0], 'UPDATE users')));
    check(count($updates) === 2, 'exactly one UPDATE per account that claimed an Apple ID, got ' . count($updates));
    foreach ($updates as [$sql]) {
        check(str_contains($sql, 'apple_id IS NULL'), 'the claim UPDATE is guarded on an empty column');
    }

    // --- Panel wiring -------------------------------------------------------

    $script = <<<'PHP'
<?php
$root = getenv('LW_PANEL_ROOT');
$files = [
    'tenant' => $root . '/src/Controllers/TenantController.php',
    'bootstrap' => $root . '/src/Bootstrap.php',
    'admin' => $root . '/src/Controllers/AdminController.php',
    'base' => $root . '/src/Controllers/BaseController.php',
    'schema' => $root . '/migrations/schema.sql',
    'migration' => $root . '/migrations/20261022_add_user_apple_id.sql',
    'css' => $root . '/public/assets/console.css',
];
foreach ($files as $key => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "missing file: $path\n");
        exit(1);
    }
    $files[$key] = (string) file_get_contents($path);
}
$checks = [
    'invite URL constant' => str_contains($files['tenant'], "public const IOS_TESTFLIGHT_INVITE_URL = 'https://testflight.apple.com/join/r1pGPj81';"),
    'clients page builds the iOS card' => str_contains($files['tenant'], '$iosSection = $this->tenantIosSection($user);'),
    'iOS card is rendered in the platform list' => str_contains($files['tenant'], '. $iosSection'),
    'Apple ID form posts to its own route' => str_contains($files['tenant'], 'action="/tenant/clients/ios/apple-id"'),
    'invite revealed only after the 5s inviting state' => str_contains($files['tenant'], 'window.setTimeout(function(){progress.hidden=true;invite.hidden=false;},5000);'),
    'card copy is Chinese, not dictionary-dependent' => str_contains($files['tenant'], '申请 TestFlight 邀请') && str_contains($files['tenant'], 'iOS 版通过 TestFlight 分发'),
    'submitted id is Chinese and shown' => str_contains($files['tenant'], '邀请已提交到 '),
    'server addresses are rendered' => str_contains($files['tenant'], 'renderIosServerAddresses()'),
    'cloudflare address label' => str_contains($files['tenant'], '会聚 Cloudflare'),
    'aliyun address label' => str_contains($files['tenant'], '会聚阿里云'),
    'addresses come from panel config, not hardcoded' => str_contains($files['tenant'], '$primary = $this->artifactBaseUrl();') && str_contains($files['tenant'], '$backup = $this->artifactMirrorBaseUrl();'),
    'card gated on membership' => str_contains($files['tenant'], 'if (!$hasMembership)'),
    'membership uses expire_at' => str_contains($files['tenant'], 'AppleId::hasValidMembership($this->userServices('),
    'recorded Apple ID is shown on the card' => str_contains($files['tenant'], 'data-ios-apple-id'),
    'submit handler enforces membership' => str_contains($files['tenant'], "throw new ApiException('membership_required', 403);"),
    'submit handler exists' => str_contains($files['tenant'], 'public function submitIosAppleId('),
    'Account Security shows the Apple ID' => str_contains($files['tenant'], '<dt>Apple ID ') && str_contains($files['tenant'], 'renderAppleIdValue($user)'),
    'Account Security copy is Chinese' => str_contains($files['tenant'], '提交于 '),
    'server address rows are styled' => str_contains($files['css'], '.tenant-client-install__ios-server '),
    'route registered in Bootstrap' => str_contains($files['bootstrap'], "\$this->router->add('POST', '/tenant/clients/ios/apple-id', [\$tenant, 'submitIosAppleId']);"),
    'admin directory reads apple_id' => str_contains($files['admin'], 'u.apple_id'),
    'admin directory shows Apple ID' => str_contains($files['admin'], '<th>Apple ID</th>'),
    'iOS icon exists' => str_contains($files['base'], "'ios' =>"),
    'schema has apple_id' => str_contains($files['schema'], 'apple_id VARCHAR(191) NULL'),
    'migration adds apple_id' => str_contains($files['migration'], 'ADD COLUMN apple_id VARCHAR(191) NULL'),
    'hidden invite stays hidden' => str_contains($files['css'], '.tenant-client-install__ios-invite[hidden]'),
    'CSS is cache-busted' => str_contains($files['base'], '/assets/console.css?v=20261022b'),
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "panel wiring check failed: $label\n");
        exit(1);
    }
}

// Forward counter: only live rules count.
$start = strpos($files['tenant'], 'public function introPage(Request $request, array $params): Response');
$end = strpos($files['tenant'], 'public function gettingStartedPage(Request $request, array $params): Response');
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fwrite(STDERR, "introPage source is not isolatable\n");
    exit(1);
}
$intro = substr($files['tenant'], $start, $end - $start);
$countStart = strpos($intro, 'SELECT COUNT(*) AS total');
while (is_int($countStart) && !str_contains(substr($intro, $countStart, 200), 'FROM forwards')) {
    $countStart = strpos($intro, 'SELECT COUNT(*) AS total', $countStart + 1);
}
if (!is_int($countStart)) {
    fwrite(STDERR, "introPage no longer counts forwards\n");
    exit(1);
}
$countQuery = substr($intro, $countStart, strpos($intro, ';', $countStart) - $countStart);
if (!str_contains($countQuery, 'AND user_deleted_at IS NULL')) {
    fwrite(STDERR, "the overview forward count must exclude user_deleted_at rows: $countQuery\n");
    exit(1);
}

$dashStart = strpos($files['tenant'], 'public function dashboard(Request $request, array $params): Response');
$dashEnd = strpos($files['tenant'], 'public function billingPage(Request $request, array $params): Response');
$dash = substr($files['tenant'], $dashStart, $dashEnd - $dashStart);
if (!str_contains($dash, 'AND user_deleted_at IS NULL')) {
    fwrite(STDERR, "the services dashboard must exclude deleted forwards\n");
    exit(1);
}

echo "ok\n";
PHP;

    $probe = tempnam(sys_get_temp_dir(), 'lw-panel-wiring-') . '.php';
    // Pass the checkout root through the script itself: a VAR=value prefix is
    // not portable through exec() on Windows.
    file_put_contents($probe, str_replace(
        '$root = getenv(\'LW_PANEL_ROOT\');',
        '$root = ' . var_export((string) realpath(__DIR__ . '/..'), true) . ';',
        $script
    ));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1';
    exec($command, $output, $exitCode);
    @unlink($probe);
    if ($exitCode !== 0) {
        fail('panel wiring checks failed: ' . implode("\n", $output));
    }

    echo "ok\n";
}
