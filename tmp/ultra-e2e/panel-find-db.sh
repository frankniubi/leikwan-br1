R=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
ls $R
echo '--- config candidates ---'
ls -la $R/config 2>/dev/null | head
grep -rlE "db_password|DB_PASSWORD|'password'" $R/config $R/.env 2>/dev/null | head
echo '--- env db ---'
grep -E 'DB_|MYSQL' $R/.env 2>/dev/null | sed 's/=.*/=<redacted>/' | head
echo '--- storage logs ---'
ls -la $R/storage/logs 2>/dev/null | tail -5
echo '--- attestation mentions in logs ---'
grep -rh 'ultra_attestation' $R/storage/logs 2>/dev/null | tail -5
grep -rh 'ultra_attestation' /www/wwwroot/v3.leikwanhost.com/leikwan-v3/public/*.log 2>/dev/null | tail -5
echo '--- php error log ---'
ls -la /www/server/php/83/var/log/ 2>/dev/null | tail -5
grep -h 'ultra_attestation' /www/server/php/83/var/log/php-fpm.log 2>/dev/null | tail -3