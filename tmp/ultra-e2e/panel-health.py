import pathlib
import subprocess

print("== panel health after the patch ==")
log = pathlib.Path("/www/server/php/83/var/log/php-fpm.log").read_text(encoding="utf-8", errors="ignore").splitlines()
recent = [line for line in log if line.startswith("[13-Sep-2026 13:5") or line.startswith("[13-Sep-2026 14:")]
print("php-fpm lines since 13:5x:", len(recent))
for line in recent[-6:]:
    print("  ", line[:160])

env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")

args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
for sql in (
    "SELECT COUNT(*) sessions, MAX(updated_at) newest_heartbeat, SUM(ultra_status_updated_at > NOW() - INTERVAL 5 MINUTE) live_5min FROM client_device_sessions;",
    "SELECT COUNT(*) leases_live FROM ultra_client_leases WHERE revoked_at IS NULL AND expires_at > NOW();",
    "SELECT COUNT(*) refusals_1h FROM ultra_lease_refusals WHERE created_at > NOW() - INTERVAL 1 HOUR;",
):
    out = subprocess.run(args + ["-e", sql], capture_output=True, text=True)
    print(out.stdout.strip() or out.stderr.strip())
