#!/usr/bin/env bash
# Deploy the Tencent low-priority dispatch change on the panel host.
# Stage first: /tmp/UltraInventoryService.php.b64 /tmp/AdminController.php.b64
#              /tmp/ProvisioningService.php.b64 /tmp/ultra-dispatch-migration.sql.b64
set -euo pipefail
SITE="${SITE:-/www/wwwroot/v3.leikwanhost.com/leikwan-v3}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BK="/root/leigod-forward-backups/ultra-dispatch-${STAMP}"
mkdir -p "$BK"
echo "== backup -> $BK"
for f in Support/UltraInventoryService.php Controllers/AdminController.php Support/ProvisioningService.php; do
  cp -a "$SITE/master/src/$f" "$BK/$(basename "$f")"
done
echo "== stage files"
base64 -d /tmp/UltraInventoryService.php.b64 > "$SITE/master/src/Support/UltraInventoryService.php"
base64 -d /tmp/AdminController.php.b64 > "$SITE/master/src/Controllers/AdminController.php"
base64 -d /tmp/ProvisioningService.php.b64 > "$SITE/master/src/Support/ProvisioningService.php"
base64 -d /tmp/ultra-dispatch-migration.sql.b64 > "$BK/migration.sql"
php -l "$SITE/master/src/Support/UltraInventoryService.php" >/dev/null
php -l "$SITE/master/src/Controllers/AdminController.php" >/dev/null
php -l "$SITE/master/src/Support/ProvisioningService.php" >/dev/null
echo "   php -l ok"
echo "== apply migration"
cat > "$BK/apply-migration.php" <<'PHP'
<?php
$site = getenv('SITE') ?: '/www/wwwroot/v3.leikwanhost.com/leikwan-v3';
$env = [];
foreach ([$site . '/.env', $site . '/master/.env'] as $f) {
    if (!is_file($f)) { continue; }
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*(.*)$/', $line, $m)) { $env[$m[1]] = trim($m[2], " \t\"'"); }
    }
    if (isset($env['DB_NAME'])) { break; }
}
if (empty($env['DB_NAME'])) { fwrite(STDERR, "no DB_NAME in .env\n"); exit(2); }
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_NAME']), $env['DB_USER'] ?? '', $env['DB_PASSWORD'] ?? '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (array_filter(array_map('trim', explode(';', file_get_contents($argv[1])))) as $stmt) {
    try { $pdo->exec($stmt); echo "   ok: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 64) . "\n"; }
    catch (PDOException $e) {
        $code = (int) $e->errorInfo[1];
        if (in_array($code, [1060, 1061, 1091], true)) { echo "   skip: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 50) . "\n"; continue; }
        throw $e;
    }
}
$row = $pdo->query("SHOW COLUMNS FROM ultra_lines LIKE 'dispatch_class'")->fetch(PDO::FETCH_ASSOC);
echo '   column dispatch_class: ' . ($row ? $row['Type'] : 'MISSING') . "\n";
$idx = $pdo->query("SHOW INDEX FROM ultra_lines WHERE Key_name = 'idx_ultra_lines_dispatch'")->fetchAll(PDO::FETCH_ASSOC);
echo '   index idx_ultra_lines_dispatch: ' . (count($idx) > 0 ? 'present' : 'MISSING') . "\n";
foreach ($pdo->query("SELECT dispatch_class, COUNT(*) c FROM ultra_lines WHERE source_id='source-home-171' GROUP BY dispatch_class") as $r) {
    echo '   ' . $r['dispatch_class'] . ' = ' . $r['c'] . "\n";
}
exit($row && count($idx) > 0 ? 0 : 4);
PHP
SITE="$SITE" php "$BK/apply-migration.php" "$BK/migration.sql"
echo "== reload php-fpm"
for unit in $(systemctl list-units --type=service --no-pager 2>/dev/null | awk '/php.*fpm.*\.service/{print $1}'); do systemctl reload "$unit" && echo "   reloaded $unit"; done
echo "== verify"
curl -fsS -o /dev/null -w '   /api/health -> %{http_code}\n' https://v3.leikwanhost.com/api/health || true
echo "rollback: $BK"
