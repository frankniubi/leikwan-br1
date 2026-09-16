import pathlib
import subprocess

env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    env[key.strip()] = value.strip().strip('"').strip("'")

print("env keys:", sorted(k for k in env if k.startswith("DB") or "ATTEST" in k))
user_key = next((k for k in ("DB_USERNAME", "DB_USER", "DB_LOGIN") if k in env), None)
print("using user key:", user_key)
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get(user_key, "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
sql = ("SELECT device_id, platform, ultra_runtime_fingerprint, LEFT(ultra_runtime_self_sha256,12) self12, "
       "updated_at FROM client_device_sessions WHERE client_id=3 ORDER BY updated_at DESC LIMIT 4;")
result = subprocess.run(args + ["-e", sql], capture_output=True, text=True)
print(result.stdout or result.stderr)
