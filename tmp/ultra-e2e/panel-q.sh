cat > /root/q.sql <<'SQL'
SELECT device_id, platform, ultra_runtime_fingerprint, LEFT(ultra_runtime_self_sha256,16) AS self16, updated_at FROM client_device_sessions WHERE client_id=3 ORDER BY updated_at DESC LIMIT 6;
SQL
mysql --defaults-file=/etc/my.cnf leikwan_v3 < /root/q.sql 2>&1 | head -20
ls -la /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/ultra/ultra-client_linux_amd64
python3 -c "import json;d=json.load(open('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json'));e=d['ultra_client_runtime'];print('manifest:',e['version'],e['sha256'],e['size_bytes']);print('attestation:',e['attestation'])"