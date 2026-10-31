set -euo pipefail
SITE=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BK=/root/leigod-forward-backups/ultra-direction-$STAMP
mkdir -p "$BK"
cp -a "$SITE/master/src/Support/UltraInventoryService.php" "$BK/"
base64 -d /tmp/UltraInventoryService.php.b64 > "$SITE/master/src/Support/UltraInventoryService.php"
php -l "$SITE/master/src/Support/UltraInventoryService.php" >/dev/null && echo '   php -l ok'
for unit in $(systemctl list-units --type=service --no-pager 2>/dev/null | awk '/php.*fpm.*\.service/{print $1}'); do systemctl reload "$unit"; done
echo '   php-fpm reloaded'
curl -fsS -o /dev/null -w '   /api/health -> %{http_code}\n' https://v3.leikwanhost.com/api/health || true
echo "BACKUP=$BK"
