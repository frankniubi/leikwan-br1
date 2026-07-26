<?php
declare(strict_types=1);

/**
 * Severity must survive the trip from the classifier to the glyph.
 *
 * The status component encodes three levels as three shapes. The call sites
 * were flattening richer classifications down into two of them with inline
 * ternaries, so the component was answering a question it had never been
 * asked:
 *
 *   - sidecarPointStatusInfo() returns three classes -- success, info and
 *     danger. `$status['class'] === 'success' ? 'ok' : 'warning'` mapped both
 *     info and danger to warning, so a disabled or erroring endpoint drew the
 *     same warning triangle as one that had simply never been installed.
 *   - `$status === 'active' ? 'ok' : 'error'` painted every non-active node
 *     the same red. `degraded` is traffic-eligible -- ProvisioningService
 *     selects on `status = 'active' OR status = 'degraded'` in a dozen
 *     places -- and `deleting` is an ordinary transient. Both read as dead.
 *
 * Flattening in the caller is invisible: the page renders, the shape is a
 * legitimate shape, and nothing is ever logged. The only way to catch it is to
 * name the mapping and assert it, which is what this file does.
 */

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

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\BaseController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\PrecisionGridUi;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// A stray warning would land in the response buffer ahead of the page.
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    fail('a diagnostic escaped: ' . $message . ' at ' . $file . ':' . $line);
});

/**
 * Neither method under test queries anything -- Database connects lazily via
 * pdo() -- so this needs no MySQL driver and does not join the pre-existing
 * "could not find driver" failures.
 */
final class StatusSeverityProbe extends BaseController
{
    public function __construct(Config $config)
    {
        parent::__construct($config, new Database($config));
    }

    /** @param array<string, mixed> $point */
    public function sidecar(array $point): array
    {
        return $this->sidecarPointStatusInfo($point);
    }

    public function state(string $status): string
    {
        return $this->resourceStatusState($status);
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-sev-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$probe = new StatusSeverityProbe(new Config($envPath));

function is(string $expected, string $actual, string $what): void
{
    if ($actual !== $expected) {
        fail($what . ': expected ' . $expected . ', got ' . $actual);
    }
}

$now = static fn(int $secondsAgo = 0): string => gmdate('Y-m-d H:i:s', time() - $secondsAgo);

// --- Sidecar endpoints ---------------------------------------------------
// Each case is the real input shape that produces the classification, so the
// mapping is asserted end to end rather than against a hand-written class name.

$cases = [
    'online' => [
        ['is_active' => 1, 'last_status' => 'ok', 'last_heartbeat_at' => $now()],
        'success',
        'ok',
    ],
    'disabled' => [
        ['is_active' => 0, 'last_status' => 'ok', 'last_heartbeat_at' => $now()],
        'danger',
        'error',
    ],
    'erroring' => [
        ['is_active' => 1, 'last_status' => 'handshake_failed', 'last_heartbeat_at' => $now()],
        'danger',
        'error',
    ],
    'never installed' => [
        ['is_active' => 1, 'last_status' => '', 'last_heartbeat_at' => ''],
        'info',
        'warning',
    ],
    'stale heartbeat' => [
        ['is_active' => 1, 'last_status' => 'ok', 'last_heartbeat_at' => $now(1200)],
        'info',
        'warning',
    ],
];

foreach ($cases as $name => [$point, $expectedClass, $expectedState]) {
    $info = $probe->sidecar($point);

    // The class drives the legacy badge and must not shift underneath us --
    // otherwise this file would happily assert a mapping from the wrong input.
    is($expectedClass, (string) ($info['class'] ?? ''), 'sidecar classification changed for ' . $name);
    is($expectedState, (string) ($info['state'] ?? ''), 'sidecar shape state for ' . $name);
}

// The point of the change: a broken endpoint must not look like an unfinished
// one. Assert the distinction directly, so collapsing them back into a single
// state fails here even if every case above were updated to match.
$broken = $probe->sidecar($cases['erroring'][0])['state'];
$unfinished = $probe->sidecar($cases['never installed'][0])['state'];
if ($broken === $unfinished) {
    fail('a failing endpoint must not render identically to one that was never installed: both ' . $broken);
}

// --- Resource statuses ---------------------------------------------------
// One mapper serves users, services, clients and nodes. Their status columns
// are all free-form VARCHAR(32) with no enum behind them, but the vocabularies
// overlap almost completely and every value below is one the code demonstrably
// writes or reads -- there are no speculative arms here.
//
//   users.status     active, pending_verification, suspended
//                    (AdminController's own <select>, and
//                    TenantRegistrationService writes pending_verification)
//   clients.status   active, disabled (both controllers' <select>)
//   services.status  active, pending (the column default), suspended,
//                    traffic_suspended, terminated (ProvisioningService)
//   nodes.status     active, degraded, deleting, inactive

is('ok', $probe->state('active'), 'an active resource is healthy');

// In progress or awaiting someone. Not healthy, but nothing is broken -- and
// these are the common cases, so painting them red trains operators to ignore
// the colour entirely.
is('warning', $probe->state('pending'), 'a service awaiting activation is not a fault -- it is the column default');
is('warning', $probe->state('pending_verification'), 'a tenant who has not clicked the email link yet is not a fault');
is('warning', $probe->state('deleting'), 'deletion was asked for; it is a transient, not a fault');
is('warning', $probe->state('degraded'), 'a degraded node still carries traffic -- ProvisioningService selects on active OR degraded');

// Not serving. These are the ones that should draw the eye.
is('error', $probe->state('suspended'), 'a suspended resource is not serving');
is('error', $probe->state('traffic_suspended'), 'a service over its traffic limit is not serving');
is('error', $probe->state('terminated'), 'a terminated service is not serving');
is('error', $probe->state('inactive'), 'an inactive node is not serving');
is('error', $probe->state('disabled'), 'a disabled client is not serving');

// The columns are VARCHAR with no enum, so the mapper must not depend on the
// exact bytes someone happened to write.
is('ok', $probe->state(' Active '), 'the mapping must tolerate case and whitespace');

// An unrecognised status must be visibly unrecognised rather than forced into a
// level. Guessing "ok" hides a real problem; guessing "error" cries wolf.
// 'provisioning' is deliberately chosen: it reads like a real value and appears
// in a docblock, but nothing in the codebase ever writes it.
is('unknown', $probe->state('provisioning'), 'an unhandled status must not be silently bucketed');
is('unknown', $probe->state(''), 'an empty status must not read as healthy');

// The three levels must stay three levels. Asserting the distinctions directly
// means collapsing any pair fails here even if every case above were edited to
// match the new behaviour.
foreach ([['pending', 'active'], ['degraded', 'inactive'], ['pending_verification', 'suspended']] as [$a, $b]) {
    if ($probe->state($a) === $probe->state($b)) {
        fail($a . ' and ' . $b . ' must not render alike: both ' . $probe->state($a));
    }
}

// --- The mapping must actually reach a distinct glyph --------------------
// A correct state string that the component does not recognise would render as
// "unknown" and undo the whole change, so close the loop through the renderer.

$shapes = [];
foreach (['ok', 'warning', 'error'] as $state) {
    $html = PrecisionGridUi::status('Label', $state);
    if (!preg_match('/<i class="pg-status-dot"[^>]*data-shape="([a-z]+)"/', $html, $m)) {
        fail('the status component emitted no shape for ' . $state);
    }
    if ($m[1] === 'unknown') {
        fail($state . ' is not a state the status component recognises');
    }
    $shapes[] = $m[1];
}
if (count(array_unique($shapes)) !== 3) {
    fail('the three severity levels must reach three distinct shapes, got: ' . implode(',', $shapes));
}

// --- No caller may flatten the mapping again -----------------------------
// Behaviour cannot observe the absence of a ternary at a call site, and these
// two were exactly how the severity was lost. A new one would be invisible.

foreach (['AdminController.php', 'TenantController.php'] as $file) {
    $source = (string) file_get_contents(__DIR__ . '/../src/Controllers/' . $file);
    if (str_contains($source, "=== 'success' ? 'ok' : 'warning'")) {
        fail($file . ' still flattens a sidecar classification; use $status[\'state\']');
    }
    if (str_contains($source, "=== 'active' ? 'ok' : 'error'")) {
        fail($file . ' still flattens a resource status; use resourceStatusState()');
    }
}

restore_error_handler();
unlink($envPath);

echo "ok\n";
