<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\MigrationRunner;
use Leikwan\Master\Support\ApiException;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

$warningHtml = is_file($envPath)
    ? '<div class="callout danger"><strong>Installer re-entry is enabled.</strong><p>This is convenient for upgrades and recovery, but you should restrict web access to <code>/install.php</code> with your own firewall, HTTP auth, or admin-only exposure.</p></div>'
    : '';

$messageHtml = '';
$statusHtml = '';
$formValues = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = collectFormValues($defaults);
    foreach (['MASTER_ADMIN_TOKEN', 'WHMCS_WEBHOOK_TOKEN', 'NODE_ENROLLMENT_TOKEN'] as $tokenKey) {
        if (trim((string) $formValues[$tokenKey]) === '') {
            $formValues[$tokenKey] = bin2hex(random_bytes(24));
        }
    }

    try {
        writeEnvFile($envPath, $formValues, $examplePath);

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
        $detailsHtml = '';
        if ($throwable instanceof ApiException && $throwable->details() !== []) {
            $detailsHtml = '<pre>' . h(json_encode($throwable->details(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }
        $messageHtml = '<div class="callout danger"><strong>Install / update failed.</strong><p>' . h($throwable->getMessage()) . '</p>' . $detailsHtml . '</div>';
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
        $statusHtml = '<div class="callout danger"><strong>Current status check failed.</strong><p>' . h($throwable->getMessage()) . '</p></div>';
    }
}

$body = $warningHtml
    . $messageHtml
    . $statusHtml
    . '<div class="grid two">
        <section class="card">
          <h2>Install / Update</h2>
          <p class="muted">Use this page for first-time setup and for safe repeatable updates. It writes <code>.env</code>, applies missing migrations and can create or reset the admin account. If Cloudflare protects your panel domain, set <code>Agent API URL</code> to a non-proxied machine-only domain for slave/client agents and install scripts.</p>
          <form method="post">
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
              <div class="field"><label>DB Password</label><input type="password" name="DB_PASSWORD" value="' . h((string) $formValues['DB_PASSWORD']) . '"></div>
              <div class="field"><label>Redis Host</label><input name="REDIS_HOST" value="' . h((string) $formValues['REDIS_HOST']) . '"></div>
              <div class="field"><label>Redis Port</label><input name="REDIS_PORT" value="' . h((string) $formValues['REDIS_PORT']) . '"></div>
              <div class="field"><label>WHMCS Base URL</label><input name="WHMCS_BASE_URL" value="' . h((string) $formValues['WHMCS_BASE_URL']) . '"></div>
              <div class="field"><label>WHMCS Identifier</label><input name="WHMCS_API_IDENTIFIER" value="' . h((string) $formValues['WHMCS_API_IDENTIFIER']) . '"></div>
              <div class="field"><label>WHMCS Secret</label><input type="password" name="WHMCS_API_SECRET" value="' . h((string) $formValues['WHMCS_API_SECRET']) . '"></div>
              <div class="field"><label>Admin Token</label><input name="MASTER_ADMIN_TOKEN" value="' . h((string) $formValues['MASTER_ADMIN_TOKEN']) . '"></div>
              <div class="field"><label>WHMCS Webhook Token</label><input name="WHMCS_WEBHOOK_TOKEN" value="' . h((string) $formValues['WHMCS_WEBHOOK_TOKEN']) . '"></div>
              <div class="field"><label>Node Enrollment Token</label><input name="NODE_ENROLLMENT_TOKEN" value="' . h((string) $formValues['NODE_ENROLLMENT_TOKEN']) . '"></div>
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

function collectFormValues(array $defaults): array
{
    $values = $defaults;
    foreach ($defaults as $key => $value) {
        if (array_key_exists($key, $_POST)) {
            $values[$key] = (string) $_POST[$key];
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
        $needsQuotes = str_contains($value, ' ') || str_contains($value, '#');
        $encoded = $needsQuotes ? '"' . addcslashes($value, "\\\"") . '"' : $value;
        $lines[] = $key . '=' . $encoded;
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
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
  <style>
    body { margin: 0; font-family: "Segoe UI", sans-serif; background: linear-gradient(180deg, #edf5ff 0%, #f6f8fc 220px); color: #172033; }
    .shell { max-width: 1240px; margin: 0 auto; padding: 28px; }
    .hero { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:18px; }
    .hero h1 { margin:0 0 8px; font-size:32px; }
    .toolbar { display:flex; align-items:center; gap:12px; }
    .locale-switch { display:flex; align-items:center; gap:8px; }
    .locale-label { color:#6b7280; font-size:13px; }
    .locale-select { border:1px solid #e5e7eb; border-radius:10px; padding:8px 10px; font-size:13px; background:#fff; }
    .muted { color: #6b7280; }
    .grid { display:grid; gap:18px; }
    .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .card {
      background:#fff; border-radius:24px; padding:22px;
      box-shadow: 0 16px 42px rgba(15,23,42,.08); border:1px solid rgba(15,23,42,.05);
      margin-bottom:18px;
    }
    .form-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:14px; }
    .field { display:flex; flex-direction:column; gap:8px; }
    .field label { font-size:13px; color:#6b7280; }
    .field input, .field select {
      border:1px solid #e5e7eb; border-radius:12px; padding:12px 14px; font-size:14px;
    }
    .button {
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      border:0; border-radius:12px; padding:10px 16px; background:#2563eb; color:#fff; font-weight:600; cursor:pointer;
    }
    .button.secondary { background:#eff6ff; color:#1e40af; }
    .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
    .callout { border-radius:18px; padding:16px 18px; margin-bottom:18px; }
    .callout.success { background:#dcfce7; color:#166534; }
    .callout.danger { background:#fee2e2; color:#991b1b; }
    code, pre { font-family: Consolas, monospace; }
    pre { background:#0f172a; color:#e2e8f0; padding:16px; border-radius:16px; overflow-x:auto; }
    .list { margin: 0; padding-left: 18px; }
    @media (max-width: 960px) { .grid.two, .form-grid { grid-template-columns: 1fr; } }
  </style>
  <link rel="stylesheet" href="/assets/precision-grid.css?v=20260725.3">
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
