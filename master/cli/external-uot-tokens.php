<?php
declare(strict_types=1);

/**
 * Operator tool for the external UoT sidecar tokens.
 *
 *   php master/cli/external-uot-tokens.php list
 *   php master/cli/external-uot-tokens.php render --host 70.36.96.183 [--listen-port 7443]
 *   php master/cli/external-uot-tokens.php rotate [--combination ID] [--apply]
 *
 * render prints the sidecar document (/etc/leikwan-external-uot.json) for a
 * host, with every forward bound to its own target. It is the only way to get
 * the document for a standalone sidecar host now that /external-uot-config
 * answers sidecar points only, and it runs on the panel host itself.
 *
 * rotate replaces external_uot_token of every enabled combination (or one).
 * Without --apply it only lists what would change. Clients pick the new token
 * up with their next config pull; pool nodes re-sync on their own; every
 * standalone sidecar host must be given its re-rendered document right after.
 * Tokens are never printed.
 */

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', (string) $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ExternalUotConfigController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\CliOptions;

function externalUotTokensUsage(): void
{
    fwrite(STDOUT, "Usage: php master/cli/external-uot-tokens.php list\n");
    fwrite(STDOUT, "       php master/cli/external-uot-tokens.php render --host HOST [--listen-port PORT]\n");
    fwrite(STDOUT, "       php master/cli/external-uot-tokens.php rotate [--combination ID] [--apply]\n");
}

$command = (string) ($argv[1] ?? '');
if ($command === '' || $command === '--help') {
    externalUotTokensUsage();
    exit(0);
}
try {
    $options = CliOptions::parse(array_slice($argv, 2), ['host', 'listen-port', 'combination'], ['apply', 'help']);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    externalUotTokensUsage();
    exit(2);
}
if (isset($options['help']) || !in_array($command, ['list', 'render', 'rotate'], true)) {
    externalUotTokensUsage();
    exit(isset($options['help']) ? 0 : 2);
}

$envPath = getenv('LEIKWAN_ENV_PATH') ?: $baseDir . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "panel .env not found\n");
    exit(2);
}
$config = new Config($envPath);
$db = new Database($config);

if ($command === 'render') {
    $host = trim((string) ($options['host'] ?? ''));
    if ($host === '') {
        externalUotTokensUsage();
        exit(2);
    }
    $controller = new ExternalUotConfigController($config);
    $payload = $controller->renderForHost($host, (int) ($options['listen-port'] ?? 0));
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

$combinationId = (int) ($options['combination'] ?? 0);
$sql = 'SELECT id, name, external_uot_sidecar_target, external_uot_token FROM forward_combinations WHERE external_uot_enabled = 1';
$params = [];
if ($combinationId > 0) {
    $sql .= ' AND id = :id';
    $params['id'] = $combinationId;
}
$rows = $db->fetchAll($sql . ' ORDER BY id ASC', $params);

if ($command === 'list') {
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "%d\t%s\t%s\ttoken_sha256=%s\n",
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['external_uot_sidecar_target'],
            substr(hash('sha256', (string) $row['external_uot_token']), 0, 12)
        ));
    }
    exit(0);
}

$apply = isset($options['apply']);
$rotated = 0;
foreach ($rows as $row) {
    $old = (string) $row['external_uot_token'];
    $new = bin2hex(random_bytes(24));
    if ($apply) {
        $db->execute(
            'UPDATE forward_combinations SET external_uot_token = :new_token, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND external_uot_token = :old_token',
            ['new_token' => $new, 'id' => (int) $row['id'], 'old_token' => $old]
        );
        $rotated++;
    }
    fwrite(STDOUT, sprintf(
        "%s %d %s %s token_sha256 %s -> %s\n",
        $apply ? 'rotated' : 'would-rotate',
        (int) $row['id'],
        (string) $row['name'],
        (string) $row['external_uot_sidecar_target'],
        substr(hash('sha256', $old), 0, 12),
        $apply ? substr(hash('sha256', $new), 0, 12) : '(new)'
    ));
}
fwrite(STDOUT, $apply ? "rotated $rotated combination(s); re-render every standalone sidecar host now\n" : "dry run; pass --apply to rotate\n");
