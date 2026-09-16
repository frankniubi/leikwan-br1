echo '### opcache settings (fpm ini)'
grep -rhiE 'opcache\.(enable|validate_timestamps|revalidate_freq|enable_cli)' /www/server/php/83/etc/ 2>/dev/null | sort -u | head -12
echo '### ini files'
ls /www/server/php/83/etc/php.d/ 2>/dev/null | head -20
echo '### fpm service uptime'
systemctl show php-fpm-83-source196.service -p ActiveEnterTimestamp -p MainPID --value
echo '### does the patched code exist on disk'
grep -c 'runtime_attestation' /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/ClientAgentController.php
echo '### recent php errors'
tail -n 5 /www/server/php/83/var/log/php-fpm.log