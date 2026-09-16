import pathlib, subprocess
env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
sql = ("SELECT device_id, platform, LEFT(ultra_runtime_fingerprint,20) fp, LEFT(ultra_runtime_self_sha256,12) self12, "
       "ultra_status_updated_at, updated_at FROM client_device_sessions WHERE client_id=3 ORDER BY updated_at DESC LIMIT 8;")
print(subprocess.run(args + ["-e", sql], capture_output=True, text=True).stdout)
sql2 = ("SELECT COUNT(*) rows_total, SUM(platform LIKE '%mac%') mac_rows, SUM(ultra_runtime_fingerprint IS NOT NULL) with_fp "
        "FROM client_device_sessions;")
print(subprocess.run(args + ["-e", sql2], capture_output=True, text=True).stdout)
