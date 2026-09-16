ls /www/server/php/83/etc 2>/dev/null | head
ls /www/server/php/83/var/run 2>/dev/null
ps -ef | grep -E "php-fpm|php_fpm" | grep -v grep
systemctl list-units --all | grep -i php-fpm
ls /etc/systemd/system | grep -i php
ls /usr/lib/systemd/system | grep -i php