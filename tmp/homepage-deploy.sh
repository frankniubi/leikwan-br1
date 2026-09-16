#!/bin/sh
set -eu
ROOT=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
chown www:www \
  "$ROOT/master/src/Support/HomePageContent.php" \
  "$ROOT/master/src/Controllers/BaseController.php" \
  "$ROOT/master/public/assets/bridge-home.css"
chmod 664 \
  "$ROOT/master/src/Support/HomePageContent.php" \
  "$ROOT/master/src/Controllers/BaseController.php" \
  "$ROOT/master/public/assets/bridge-home.css"
systemctl reload php-fpm-83-source196
ls -l \
  "$ROOT/master/src/Support/HomePageContent.php" \
  "$ROOT/master/src/Controllers/BaseController.php" \
  "$ROOT/master/public/assets/bridge-home.css"
echo deploy-ok
