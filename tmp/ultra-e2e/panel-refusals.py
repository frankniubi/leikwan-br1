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
    "SELECT COUNT(*) refusals, SUM(error_code='lease_runtime_unattested') unattested FROM ultra_lease_refusals;",
    "SELECT error_code, COUNT(*) FROM ultra_lease_refusals GROUP BY error_code ORDER BY 2 DESC LIMIT 8;",
    "SELECT COUNT(*) total, SUM(ultra_runtime_fingerprint IS NOT NULL) with_fp FROM client_device_sessions;",
):
    out = subprocess.run(args + ["-e", sql], capture_output=True, text=True)
    print(out.stdout or out.stderr)
