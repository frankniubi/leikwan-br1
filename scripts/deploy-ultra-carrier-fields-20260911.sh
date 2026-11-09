#!/usr/bin/env bash
# Deploy the Ultra line carrier fields (isp / provider / in_line_type) on the panel host.
#
# Run ON the panel server. Stage these first (base64, one line each):
#   /tmp/UltraInventoryService.php.b64   master/src/Support/UltraInventoryService.php
#   /tmp/AdminController.php.b64         master/src/Controllers/AdminController.php
#   /tmp/ultra-carrier-migration.sql.b64 master/migrations/20260911_add_ultra_line_carrier_fields.sql
#
# Idempotent: duplicate column/index errors are ignored so a re-run is safe.
set -euo pipefail
SITE="${SITE:-/www/wwwroot/v3.leikwanhost.com/leikwan-v3}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BK="/root/leigod-forward-backups/ultra-carrier-${STAMP}"
mkdir -p "$BK"

echo "== backup -> $BK"
cp -a "$SITE/master/src/Support/UltraInventoryService.php" "$BK/"
cp -a "$SITE/master/src/Controllers/AdminController.php" "$BK/"
cp -a "$SITE/master/migrations/schema.sql" "$BK/" 2>/dev/null || true

echo "== stage files"
base64 -d /tmp/UltraInventoryService.php.b64 > "$SITE/master/src/Support/UltraInventoryService.php"
base64 -d /tmp/AdminController.php.b64 > "$SITE/master/src/Controllers/AdminController.php"
base64 -d /tmp/ultra-carrier-migration.sql.b64 > "$BK/20260911_add_ultra_line_carrier_fields.sql"
php -l "$SITE/master/src/Support/UltraInventoryService.php" >/dev/null
php -l "$SITE/master/src/Controllers/AdminController.php" >/dev/null
echo "   php -l ok"

echo "== apply migration"
cat > "$BK/apply-migration.php" <<'PHP'
<?php
$site = getenv('SITE') ?: '/www/wwwroot/v3.leikwanhost.com/leikwan-v3';
$files = [$site . '/.env', $site . '/master/.env', $site . '/master/config/.env'];
$env = [];
foreach ($files as $f) {
    if (!is_file($f)) { continue; }
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
            $env[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    if (isset($env['DB_NAME'])) { break; }
}
if (empty($env['DB_NAME'])) { fwrite(STDERR, "no DB_NAME in .env\n"); exit(2); }
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_NAME']), $env['DB_USER'] ?? '', $env['DB_PASSWORD'] ?? '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents($argv[1]);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    try {
        $pdo->exec($stmt);
        echo "   ok: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 70) . "\n";
    } catch (PDOException $e) {
        $code = (int) $e->errorInfo[1];
        if (in_array($code, [1060, 1061, 1091], true)) {
            echo "   skip (already applied): " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . "\n";
            continue;
        }
        throw $e;
    }
}
foreach (['in_line_type', 'isp', 'provider'] as $column) {
    $row = $pdo->query("SHOW COLUMNS FROM ultra_lines LIKE " . $pdo->quote($column))->fetch(PDO::FETCH_ASSOC);
    echo "   column " . $column . ': ' . ($row ? $row['Type'] : 'MISSING') . "\n";
    if (!$row) { exit(3); }
}
$idx = $pdo->query("SHOW INDEX FROM ultra_lines WHERE Key_name = 'idx_ultra_lines_carrier'")->fetchAll(PDO::FETCH_ASSOC);
echo '   index idx_ultra_lines_carrier: ' . (count($idx) > 0 ? 'present' : 'MISSING') . "\n";
echo '   ultra_lines rows: ' . $pdo->query('SELECT COUNT(*) FROM ultra_lines')->fetchColumn() . "\n";
exit(count($idx) > 0 ? 0 : 4);
PHP
SITE="$SITE" php "$BK/apply-migration.php" "$BK/20260911_add_ultra_line_carrier_fields.sql"

echo "== reload php-fpm"
FPM_UNITS="$(systemctl list-units --type=service --no-pager 2>/dev/null | awk '/php.*fpm.*\.service/{print $1}')"
if [ -n "$FPM_UNITS" ]; then
  for unit in $FPM_UNITS; do systemctl reload "$unit" && echo "   reloaded $unit"; done
else
  echo "   WARN: php-fpm unit not found"
fi

echo "== verify"
curl -fsS -o /dev/null -w '   /api/health -> %{http_code}\n' https://v3.leikwanhost.com/api/health || true
echo "done. rollback: cp -a $BK/UltraInventoryService.php $BK/AdminController.php $SITE/master/src/... (see $BK)"
