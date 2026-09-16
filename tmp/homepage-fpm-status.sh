systemctl status php-fpm-83-source196.service --no-pager || true
echo ---
systemctl status php-fpm-82-source196.service --no-pager || true
echo ---
ls /www/server/php/83/sbin
echo ---
ss -lntp | sed -n "1,80p"
echo ---
nginx -T 2>/dev/null | sed -n "/enable-php/p;/fastcgi_pass/p;/v3.leikwanhost.com/p" | head -40