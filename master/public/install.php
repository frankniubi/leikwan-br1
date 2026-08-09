<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\MigrationRunner;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$root = dirname(__DIR__);
$envPath = $root . '/.env';
$examplePath = $root . '/.env.example';
$existingValues = readEnvFile(is_file($envPath) ? $envPath : $examplePath);
$installed = is_file($envPath);
$bootstrapTokenPath = $root . '/.installer-access-token';

try {
    $accessValues = $existingValues;
    if (!$installed) {
        $accessValues['INSTALLER_ACCESS_TOKEN'] = bootstrapInstallerToken($bootstrapTokenPath);
    }
} catch (Throwable $throwable) {
    error_log('installer bootstrap token initialization failed: ' . $throwable::class);
    http_response_code(500);
    echo renderShell(
        'LeiKwan Bridge Installer',
        '<div class="callout danger"><strong>Installer unavailable.</strong><p>Installer access could not be initialized. Check the server logs.</p></div>'
    );
    exit;
}

if (!installerSessionAuthorized($accessValues)) {
    $unlockError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('installer_unlock_token', $_POST)) {
        $expectedToken = configuredInstallerAccessToken($accessValues);
        $providedToken = trim((string) $_POST['installer_unlock_token']);
        if ($expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            session_regenerate_id(true);
            $_SESSION['installer_access_hash'] = hash('sha256', $expectedToken);
            header('Location: /install.php');
            exit;
        }
        $unlockError = '<div class="callout danger"><strong>Access denied.</strong><p>The installer access token was not accepted.</p></div>';
    }

    http_response_code(403);
    $accessHint = $installed
        ? 'Use the local <code>INSTALLER_ACCESS_TOKEN</code>. Legacy installations may use their admin API token once, then save to generate a dedicated token.'
        : 'Read the one-time token from <code>.installer-access-token</code> on the server. It is never sent to an unauthenticated browser.';
    $body = $unlockError
        . '<section class="card"><h2>Installer Access</h2>'
        . '<p class="muted">' . $accessHint . '</p>'
        . '<form method="post" autocomplete="off">'
        . '<div class="field"><label>Installer Access Token</label><input type="password" name="installer_unlock_token" required></div>'
        . '<div class="actions"><button class="button" type="submit">Unlock Installer</button></div>'
        . '</form></section>';
    echo renderShell('LeiKwan Bridge Installer', $body);
    exit;
}

$installerCsrf = (string) ($_SESSION['installer_csrf_token'] ?? '');
if ($installerCsrf === '') {
    $installerCsrf = bin2hex(random_bytes(32));
    $_SESSION['installer_csrf_token'] = $installerCsrf;
}

$defaults = array_merge([
    'APP_NAME' => '利群會聚 LeiKwan Bridge',
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_URL' => '',
    'AGENT_API_URL' => '',
    'APP_TIMEZONE' => 'Asia/Shanghai',
    'AUTO_RUN_MIGRATIONS' => 'true',
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'leikwan_master',
    'DB_USER' => 'leikwan',
    'DB_PASSWORD' => '',
    'REDIS_HOST' => '127.0.0.1',
    'REDIS_PORT' => '6379',
    'MASTER_ADMIN_TOKEN' => '',
    'INSTALLER_ACCESS_TOKEN' => '',
    'WHMCS_WEBHOOK_TOKEN' => '',
    'NODE_ENROLLMENT_TOKEN' => '',
    'WHMCS_BASE_URL' => 'https://buy.leikwanhost.com',
    'WHMCS_API_IDENTIFIER' => '',
    'WHMCS_API_SECRET' => '',
    'DEFAULT_ENDPOINT_COUNT' => '1',
    'DEFAULT_CLIENT_MODE' => 'single',
    'DEFAULT_TRANSPORT_MODE' => 'tcp',
    'DEFAULT_NODE_GROUP' => 'default',
    'DEFAULT_CDN_HOST' => 'edge.example.com',
    'DEFAULT_TLS_CERT_REF' => 'default-edge-cert',
    'MAX_TASK_BATCH' => '20',
    'NODE_HEARTBEAT_TTL_SECONDS' => '90',
], $existingValues);
foreach (['MASTER_ADMIN_TOKEN', 'INSTALLER_ACCESS_TOKEN', 'WHMCS_WEBHOOK_TOKEN', 'NODE_ENROLLMENT_TOKEN'] as $tokenKey) {
    if (str_starts_with(strtolower(trim((string) ($defaults[$tokenKey] ?? ''))), 'change_me')) {
        $defaults[$tokenKey] = '';
    }
}

$warningHtml = $installed
    ? '<div class="callout"><strong>Authenticated installer session.</strong><p>Re-entry is protected by the local installer token. Keep network access to <code>/install.php</code> restricted as an additional control.</p></div>'
    : '';

$messageHtml = '';
$statusHtml = '';
$formValues = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $installerLock = null;
    try {
        validateInstallerCsrf((string) ($_POST['installer_csrf_token'] ?? ''), $installerCsrf);
        $installerLock = acquireInstallerLock($root . '/.installer.lock');
        $formValues = collectFormValues($defaults);
        foreach (['MASTER_ADMIN_TOKEN', 'INSTALLER_ACCESS_TOKEN', 'WHMCS_WEBHOOK_TOKEN', 'NODE_ENROLLMENT_TOKEN'] as $tokenKey) {
            if (trim((string) $formValues[$tokenKey]) === '') {
                $formValues[$tokenKey] = bin2hex(random_bytes(24));
            }
        }

        writeEnvFile($envPath, $formValues, $examplePath);
        $_SESSION['installer_access_hash'] = hash('sha256', (string) $formValues['INSTALLER_ACCESS_TOKEN']);
        if (!$installed && is_file($bootstrapTokenPath)) {
            @unlink($bootstrapTokenPath);
        }

        $config = new Config($envPath);
        $db = new Database($config);
        $db->pdo();

        $runner = new MigrationRunner($config, $db);
        $applied = $runner->applyPending();
        $status = $runner->status();

        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $adminName = trim((string) ($_POST['admin_name'] ?? 'Platform Admin'));
        $adminChanged = null;
        if ($adminEmail !== '' && $adminPassword !== '') {
            $adminChanged = upsertAdminUser($db, $adminEmail, $adminPassword, $adminName);
        }

        $messageHtml = '<div class="callout success"><strong>Install / update completed.</strong><p>Environment file saved, database reachable, pending migrations applied.</p></div>';
        $messageHtml .= '<div class="actions"><a class="button" href="/login">Open Login</a><a class="button secondary" href="/admin">Open Admin</a></div>';

        $statusHtml = buildStatusHtml([
            'db_connection' => 'ok',
            'applied_now' => $applied,
            'pending_after' => $status['pending'],
            'admin_result' => $adminChanged,
        ]);
    } catch (Throwable $throwable) {
        error_log('installer operation failed: ' . $throwable::class);
        $messageHtml = '<div class="callout danger"><strong>Install / update failed.</strong><p>Install / update could not be completed. Check the server logs.</p></div>';
    } finally {
        releaseInstallerLock($installerLock);
    }
} else {
    try {
        if (is_file($envPath)) {
            $config = new Config($envPath);
            $db = new Database($config);
            $db->pdo();
            $runner = new MigrationRunner($config, $db);
            $statusHtml = buildStatusHtml([
                'db_connection' => 'ok',
                'applied_now' => [],
                'pending_after' => $runner->pendingMigrations(),
                'admin_result' => existingAdminState($db),
            ]);
        }
    } catch (Throwable $throwable) {
        error_log('installer status check failed: ' . $throwable::class);
        $statusHtml = '<div class="callout danger"><strong>Current status check failed.</strong><p>Status is temporarily unavailable. Check the server logs.</p></div>';
    }
}

$body = $warningHtml
    . $messageHtml
    . $statusHtml
    . '<div class="grid two">
        <section class="card">
          <h2>Install / Update</h2>
          <p class="muted">Use this page for first-time setup and for safe repeatable updates. It writes <code>.env</code>, applies missing migrations and can create or reset the admin account. If Cloudflare protects your panel domain, set <code>Agent API URL</code> to a non-proxied machine-only domain for slave/client agents and install scripts.</p>
          <form method="post" autocomplete="off">
            <input type="hidden" name="installer_csrf_token" value="' . h($installerCsrf) . '">
            <div class="form-grid">
              <div class="field"><label>App Name</label><input name="APP_NAME" value="' . h((string) $formValues['APP_NAME']) . '"></div>
              <div class="field"><label>App URL</label><input name="APP_URL" value="' . h((string) $formValues['APP_URL']) . '" placeholder="https://panel.example.com"></div>
              <div class="field"><label>Agent API URL</label><input name="AGENT_API_URL" value="' . h((string) ($formValues['AGENT_API_URL'] ?? '')) . '" placeholder="https://api.panel.example.com"></div>
              <div class="field"><label>Timezone</label><input name="APP_TIMEZONE" value="' . h((string) $formValues['APP_TIMEZONE']) . '"></div>
              <div class="field"><label>Auto Run Migrations</label>
                <select name="AUTO_RUN_MIGRATIONS">
                  <option value="true"' . ((string) $formValues['AUTO_RUN_MIGRATIONS'] === 'true' ? ' selected' : '') . '>true</option>
                  <option value="false"' . ((string) $formValues['AUTO_RUN_MIGRATIONS'] === 'false' ? ' selected' : '') . '>false</option>
                </select>
              </div>
              <div class="field"><label>DB Driver</label>
                <select name="DB_DRIVER">
                  <option value="mysql"' . ((string) $formValues['DB_DRIVER'] === 'mysql' ? ' selected' : '') . '>mysql</option>
                </select>
              </div>
              <div class="field"><label>DB Host</label><input name="DB_HOST" value="' . h((string) $formValues['DB_HOST']) . '"></div>
              <div class="field"><label>DB Port</label><input name="DB_PORT" value="' . h((string) $formValues['DB_PORT']) . '"></div>
              <div class="field"><label>DB Name / SQLite Path</label><input name="DB_NAME" value="' . h((string) $formValues['DB_NAME']) . '"></div>
              <div class="field"><label>DB User</label><input name="DB_USER" value="' . h((string) $formValues['DB_USER']) . '"></div>
              <div class="field"><label>DB Password</label><input type="password" name="DB_PASSWORD" placeholder="Leave blank to keep the current value"></div>
              <div class="field"><label>Redis Host</label><input name="REDIS_HOST" value="' . h((string) $formValues['REDIS_HOST']) . '"></div>
              <div class="field"><label>Redis Port</label><input name="REDIS_PORT" value="' . h((string) $formValues['REDIS_PORT']) . '"></div>
              <div class="field"><label>WHMCS Base URL</label><input name="WHMCS_BASE_URL" value="' . h((string) $formValues['WHMCS_BASE_URL']) . '"></div>
              <div class="field"><label>WHMCS Identifier</label><input name="WHMCS_API_IDENTIFIER" value="' . h((string) $formValues['WHMCS_API_IDENTIFIER']) . '"></div>
              <div class="field"><label>WHMCS Secret</label><input type="password" name="WHMCS_API_SECRET" placeholder="Leave blank to keep the current value"></div>
              <div class="field"><label>Admin Token</label><input type="password" name="MASTER_ADMIN_TOKEN" placeholder="Leave blank to keep or generate securely"></div>
              <div class="field"><label>WHMCS Webhook Token</label><input type="password" name="WHMCS_WEBHOOK_TOKEN" placeholder="Leave blank to keep or generate securely"></div>
              <div class="field"><label>Node Enrollment Token</label><input type="password" name="NODE_ENROLLMENT_TOKEN" placeholder="Leave blank to keep or generate securely"></div>
            </div>
            <h3>Admin Account</h3>
            <div class="form-grid">
              <div class="field"><label>Admin Name</label><input name="admin_name" value="Platform Admin"></div>
              <div class="field"><label>Admin Email</label><input type="email" name="admin_email" placeholder="admin@example.com"></div>
              <div class="field"><label>Admin Password</label><input type="password" name="admin_password" placeholder="Set or reset admin password"></div>
            </div>
            <div class="actions">
              <button class="button" type="submit">Run Install / Update</button>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>What This Does</h2>
          <ul class="list">
            <li>Writes or updates the <code>.env</code> file.</li>
            <li>Tests database connectivity immediately.</li>
            <li>Creates the <code>schema_migrations</code> table if missing.</li>
            <li>Applies every pending SQL migration exactly once.</li>
            <li>Creates or resets the admin account when credentials are supplied.</li>
            <li>Keeps update flow repeatable so you do not need to remember manual steps.</li>
          </ul>
          <p class="muted">For regular app requests, pending migrations can also auto-apply when <code>AUTO_RUN_MIGRATIONS=true</code>. This installer currently targets MySQL/MariaDB-style deployments.</p>
        </section>
      </div>';

echo renderShell('利群會聚 LeiKwan Bridge Installer', $body);

function readEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $values[trim($parts[0])] = trim((string) $parts[1], "\"'");
    }
    return $values;
}

function bootstrapInstallerToken(string $path): string
{
    if (is_file($path)) {
        $existing = trim((string) file_get_contents($path));
        if (strlen($existing) >= 32) {
            return $existing;
        }
        throw new RuntimeException('installer_bootstrap_token_invalid');
    }

    $token = bin2hex(random_bytes(32));
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        $existing = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if (strlen($existing) >= 32) {
            return $existing;
        }
        throw new RuntimeException('installer_bootstrap_token_create_failed');
    }
    try {
        if (fwrite($handle, $token . PHP_EOL) === false) {
            throw new RuntimeException('installer_bootstrap_token_write_failed');
        }
    } finally {
        fclose($handle);
    }
    @chmod($path, 0600);
    return $token;
}

function configuredInstallerAccessToken(array $values): string
{
    foreach (['INSTALLER_ACCESS_TOKEN', 'MASTER_ADMIN_TOKEN'] as $key) {
        $token = trim((string) ($values[$key] ?? ''));
        if (strlen($token) >= 24 && !str_starts_with(strtolower($token), 'change_me')) {
            return $token;
        }
    }
    return '';
}

function installerSessionAuthorized(array $values): bool
{
    $expectedToken = configuredInstallerAccessToken($values);
    $sessionHash = (string) ($_SESSION['installer_access_hash'] ?? '');
    return $expectedToken !== ''
        && $sessionHash !== ''
        && hash_equals(hash('sha256', $expectedToken), $sessionHash);
}

function validateInstallerCsrf(string $providedToken, string $expectedToken): void
{
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        throw new RuntimeException('installer_request_invalid');
    }
}

function collectFormValues(array $defaults): array
{
    $values = $defaults;
    $secretKeys = [
        'DB_PASSWORD',
        'WHMCS_API_SECRET',
        'MASTER_ADMIN_TOKEN',
        'INSTALLER_ACCESS_TOKEN',
        'WHMCS_WEBHOOK_TOKEN',
        'NODE_ENROLLMENT_TOKEN',
    ];
    foreach ($defaults as $key => $value) {
        if (array_key_exists($key, $_POST)) {
            $submitted = (string) $_POST[$key];
            if (in_array($key, $secretKeys, true) && trim($submitted) === '' && trim((string) $value) !== '') {
                continue;
            }
            $values[$key] = $submitted;
        }
    }
    return $values;
}

function writeEnvFile(string $path, array $values, string $templatePath): void
{
    $orderedKeys = [];
    foreach (file($templatePath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key] = explode('=', $trimmed, 2);
        $orderedKeys[] = trim($key);
    }
    foreach (array_keys($values) as $key) {
        if (!in_array($key, $orderedKeys, true)) {
            $orderedKeys[] = $key;
        }
    }

    $lines = [];
    foreach ($orderedKeys as $key) {
        $value = (string) ($values[$key] ?? '');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            throw new RuntimeException('installer_env_key_invalid');
        }
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new RuntimeException('installer_env_value_invalid');
        }
        $needsQuotes = str_contains($value, ' ') || str_contains($value, '#');
        $encoded = $needsQuotes ? '"' . addcslashes($value, "\\\"") . '"' : $value;
        $lines[] = $key . '=' . $encoded;
    }
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
    try {
        $bytes = file_put_contents($temporaryPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
        if ($bytes === false) {
            throw new RuntimeException('installer_env_write_failed');
        }
        @chmod($temporaryPath, 0600);
        if (!@rename($temporaryPath, $path)) {
            throw new RuntimeException('installer_env_replace_failed');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}

function acquireInstallerLock(string $path)
{
    $handle = @fopen($path, 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('installer_lock_unavailable');
    }
    @chmod($path, 0600);
    return $handle;
}

function releaseInstallerLock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

function upsertAdminUser(Database $db, string $email, string $password, string $name): array
{
    $existing = $db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
    $payload = [
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'status' => 'active',
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ];

    if ($existing === null) {
        $payload['api_token'] = bin2hex(random_bytes(24));
        $payload['created_at'] = gmdate('Y-m-d H:i:s');
        $id = $db->insert('users', $payload);
        return ['action' => 'created', 'email' => $email, 'id' => $id];
    }

    $db->execute(
        'UPDATE users
         SET name = :name,
             password_hash = :password_hash,
             role = :role,
             status = :status,
             updated_at = :updated_at
         WHERE id = :id',
        [
            'id' => $existing['id'],
            'name' => $payload['name'],
            'password_hash' => $payload['password_hash'],
            'role' => $payload['role'],
            'status' => $payload['status'],
            'updated_at' => $payload['updated_at'],
        ]
    );

    return ['action' => 'updated', 'email' => $email, 'id' => $existing['id']];
}

function existingAdminState(Database $db): array
{
    try {
        $row = $db->fetchOne('SELECT id, email, name FROM users WHERE role = :role ORDER BY id ASC LIMIT 1', ['role' => 'admin']);
        if ($row === null) {
            return ['action' => 'missing'];
        }
        return ['action' => 'present', 'email' => $row['email'], 'id' => $row['id']];
    } catch (Throwable) {
        return ['action' => 'unknown'];
    }
}

function buildStatusHtml(array $payload): string
{
    $applied = $payload['applied_now'] ?? [];
    $pending = $payload['pending_after'] ?? [];
    $admin = $payload['admin_result'] ?? null;

    $appliedItems = '';
    foreach ($applied as $migration) {
        $appliedItems .= '<li>' . h((string) $migration) . '</li>';
    }
    if ($appliedItems === '') {
        $appliedItems = '<li>No new migrations applied in this run.</li>';
    }

    $pendingItems = '';
    foreach ($pending as $migration) {
        $pendingItems .= '<li>' . h((string) $migration) . '</li>';
    }
    if ($pendingItems === '') {
        $pendingItems = '<li>No pending migrations.</li>';
    }

    $adminText = 'Not checked';
    if (is_array($admin)) {
        $adminText = match ((string) ($admin['action'] ?? '')) {
            'created' => 'Admin created: ' . h((string) $admin['email']),
            'updated' => 'Admin updated: ' . h((string) $admin['email']),
            'present' => 'Admin present: ' . h((string) $admin['email']),
            'missing' => 'No admin account found yet.',
            default => 'Admin state unknown.',
        };
    }

    return '<div class="grid two">
        <section class="card">
          <h2>Current Status</h2>
          <p><strong>Database:</strong> ' . h((string) ($payload['db_connection'] ?? 'unknown')) . '</p>
          <p><strong>Admin:</strong> ' . $adminText . '</p>
          <h3>Applied Now</h3>
          <ul class="list">' . $appliedItems . '</ul>
        </section>
        <section class="card">
          <h2>Pending After Run</h2>
          <ul class="list">' . $pendingItems . '</ul>
        </section>
      </div>';
}

function renderShell(string $title, string $body): string
{
    return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . h($title) . '</title>
  <link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
  <link rel="stylesheet" href="/assets/operator-console.css?v=20260725">
  <link rel="stylesheet" href="/assets/bridge-modern.css?v=20260729a">
</head>
<body class="pg-public">
  <div class="shell">
    <div class="hero">
      <div>
  <h1>利群會聚 LeiKwan Bridge Installer</h1>
        <p class="muted">Idempotent setup and upgrade entrypoint for the Master panel.</p>
      </div>
      <div class="toolbar">
        <div class="locale-switch">
          <label for="locale-switcher" class="locale-label">Language</label>
          <select id="locale-switcher" class="locale-select">
            <option value="en">EN</option>
            <option value="zh-Hans">Simplified Chinese</option>
            <option value="zh-TW">Traditional Chinese</option>
          </select>
        </div>
      </div>
    </div>
    ' . $body . '
  </div>
  <script>
    window.LEIKWAN_I18N = {
      defaultLocale: \'en\',
      supported: [\'en\', \'zh-TW\', \'zh-Hans\'],
      basePath: \'/i18n\'
    };
  </script>
  <script src="/i18n/patch.js"></script>
</body>
</html>';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
