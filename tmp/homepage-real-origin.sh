set -eu
echo ---HOST---
hostname
hostname -I
echo ---V3-PATH---
ls -ld /www/wwwroot/v3.leikwanhost.com /www/wwwroot/v3.leikwanhost.com/leikwan-v3 2>/dev/null || true
ls /www/wwwroot 2>/dev/null | head
echo ---NGINX-VHOST---
ls /www/server/panel/vhost/nginx 2>/dev/null | grep -i leikwan | head
grep -n "root \|enable-php\|server_name" /www/server/panel/vhost/nginx/v3.leikwanhost.com.conf 2>/dev/null | head -30
echo ---PHP---
systemctl list-units --type=service --all | grep -Ei "php-fpm|nginx" | head
ls /tmp/php-cgi-*.sock 2>/dev/null
echo ---HOME-FILES---
ls -l /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/HomePageContent.php /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/public/assets/bridge-home.css 2>/dev/null || true
grep -n "bridge-home.css" /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/BaseController.php 2>/dev/null | head
echo ---BACKUPS---
ls -ld /root/leikwan-br1-backups /root/leikwan-br1-backups/homepage-pre-redesign 2>/dev/null || true