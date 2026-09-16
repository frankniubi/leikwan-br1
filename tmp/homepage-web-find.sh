ss -lntp
echo ---DOCKER---
docker ps --format "{{.Names}} {{.Ports}} {{.Status}}" 2>/dev/null | head
echo ---BT---
ls /www/server/nginx/sbin 2>/dev/null
ls /www/server/nginx/conf/vhost 2>/dev/null | head
ps -ef | sed -n "1,5p; /nginx/p; /openresty/p; /httpd/p; /caddy/p; /php-fpm/p" | grep -v grep
echo ---PHP-SOCK---
find /tmp /dev/shm /www/server /run -name "*php*.sock" 2>/dev/null | head
echo ---VHOST---
ls /www/server/panel/vhost/nginx 2>/dev/null | head
grep -n "v3.leikwanhost.com\|enable-php\|fastcgi_pass" /www/server/panel/vhost/nginx/* 2>/dev/null | head -40