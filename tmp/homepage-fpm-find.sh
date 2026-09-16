systemctl list-units --type=service --state=running | grep -Ei "php|nginx|httpd"
ls /www/server/php 2>/dev/null
ls /etc/init.d | grep -i php
ps aux | grep php-fpm | grep -v grep | head