cat > /root/q2.sql <<'SQL'
SELECT device_id, platform, LEFT(ultra_runtime_fingerprint,24) fp, LEFT(ultra_runtime_self_sha256,16) self16, ultra_runtime_attested_at, ultra_status_updated_at
FROM client_device_sessions
WHERE ultra_runtime_fingerprint IS NOT NULL
ORDER BY ultra_runtime_attested_at DESC LIMIT 10;
SELECT COUNT(*) refused FROM ultra_lease_refusals;
SQL
mysql --defaults-file=/etc/my.cnf leikwan_v3 < /root/q2.sql 2>&1 | head -20
