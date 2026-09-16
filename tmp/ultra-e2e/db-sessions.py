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
    "SELECT device_id, platform, last_ip, ultra_status_updated_at, last_heartbeat_at, "
    "LENGTH(ultra_status_json) AS status_bytes, ultra_runtime_fingerprint "
    "FROM client_device_sessions WHERE client_id=3 ORDER BY updated_at DESC;",
    "SELECT COUNT(*) AS sessions_with_status, MAX(ultra_status_updated_at) AS newest FROM client_device_sessions WHERE ultra_status_json IS NOT NULL;",
    "SELECT DISTINCT platform FROM client_device_sessions ORDER BY platform LIMIT 20;",
):
    out = subprocess.run(args + ["-e", sql], capture_output=True, text=True)
    print(out.stdout or out.stderr)
