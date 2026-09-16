import pathlib
import subprocess

env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    env[key.strip()] = value.strip().strip('"').strip("'")

args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
for sql in (
    "SELECT client_id, device_id, ultra_status_updated_at, last_heartbeat_at, last_seen_at, updated_at FROM client_device_sessions WHERE client_id=3 ORDER BY updated_at DESC LIMIT 5;",
    "SHOW COLUMNS FROM client_device_sessions LIKE 'ultra%';",
):
    out = subprocess.run(args + ["-e", sql], capture_output=True, text=True)
    print(out.stdout or out.stderr)
