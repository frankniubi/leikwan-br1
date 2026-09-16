echo ---NGINX---
ls -l /www/server/nginx/sbin/nginx
/www/server/nginx/sbin/nginx -v 2>&1 | head
systemctl status nginx --no-pager | head -20
echo ---BT---
ps -ef | grep -E "BT-Panel|btpython|gunicorn" | grep -v grep | head
echo ---CURL-LOCAL---
curl -sI --max-time 5 http://127.0.0.1/ | head -20
curl -skI --max-time 5 https://127.0.0.1/ | head -20
echo ---CURL-V3---
curl -skI --max-time 8 https://v3.leikwanhost.com/ | head -20
echo ---PHP-CGI---
ls -l /tmp/php-cgi-83.sock /tmp/php-cgi-82.sock 2>/dev/null
ls /www/server/php/83/var/run 2>/dev/null
cat /www/server/php/83/etc/php-fpm.d/www.conf 2>/dev/null | grep -E "listen|pid" | head