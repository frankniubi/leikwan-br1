import pathlib, subprocess
env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
sql = ("SELECT device_id, platform, LEFT(ultra_runtime_fingerprint,24) fp, LEFT(ultra_runtime_self_sha256,16) self16, "
       "ultra_runtime_attested_at FROM client_device_sessions WHERE ultra_runtime_fingerprint IS NOT NULL "
       "ORDER BY ultra_runtime_attested_at DESC LIMIT 8;")
print(subprocess.run(args + ["-e", sql], capture_output=True, text=True).stdout)
