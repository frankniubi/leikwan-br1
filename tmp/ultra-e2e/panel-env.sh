echo '### php-fpm env'
grep -rh 'env\[' /www/server/php/83/etc/php-fpm.d/*.conf 2>/dev/null | head -20
echo '### env files under site'
find /www/wwwroot/v3.leikwanhost.com -maxdepth 3 -name '.env*' 2>/dev/null | head
echo '### grep DB_NAME in env-ish files'
grep -rl 'DB_NAME' /www/wwwroot/v3.leikwanhost.com 2>/dev/null | head -5
echo '### nginx fastcgi params for v3'
grep -A3 -B3 'fastcgi_param' /www/server/panel/vhost/nginx/v3.leikwanhost.com.conf 2>/dev/null | head -30
ls /www/server/panel/vhost/nginx/ | head -20