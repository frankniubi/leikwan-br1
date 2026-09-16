import pathlib
import subprocess

env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
for sql in (
    "SELECT COUNT(*) refusals FROM ultra_lease_refusals;",
    "SELECT COUNT(*) live FROM ultra_client_leases WHERE revoked_at IS NULL AND expires_at > NOW();",
    "SELECT COUNT(*) sessions, SUM(ultra_runtime_fingerprint IS NOT NULL) with_fp FROM client_device_sessions;",
):
    print(subprocess.run(args + ["-e", sql], capture_output=True, text=True).stdout.strip())
log = pathlib.Path("/www/server/php/83/var/log/php-fpm.log").read_text(encoding="utf-8", errors="ignore")
print("mismatch lines:", log.count("ultra_attestation_mismatch"))
