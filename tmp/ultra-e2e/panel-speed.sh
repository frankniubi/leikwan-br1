echo '### docroot'
grep -rhE '^\s*(root|server_name|listen)\s' /www/server/panel/vhost/nginx/*.conf 2>/dev/null | sed -n '1,20p'
echo '### bandwidth/limits'
nproc; grep -m1 'model name' /proc/cpuinfo; free -m | head -2
echo '### localhost PHP artifact speed'
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
curl -s -o /dev/null -w 'php http=%{http_code} bytes=%{size_download} time=%{time_total} speed=%{speed_download}\n' -H 'Host: v3.leikwanhost.com' http://127.0.0.1/artifacts/ultra/ultra-client_linux_amd64
echo '### disk read speed'
dd if=artifacts/ultra/ultra-client_linux_amd64 of=/dev/null bs=1M 2>&1 | tail -1
echo '### php-fpm pool settings'
grep -hE '^(pm|pm.max_children|output_buffering|memory_limit)' /www/server/php/83/etc/php.ini /www/server/php/83/etc/php-fpm.d/*.conf 2>/dev/null | head -12