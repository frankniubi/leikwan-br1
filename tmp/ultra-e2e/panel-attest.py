import pathlib
import subprocess

env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    env[key.strip()] = value.strip().strip('"').strip("'")

print("attestation settings:", {k: v for k, v in env.items() if "ATTESTATION" in k})
print("db:", {k: env.get(k) for k in ("DB_HOST", "DB_PORT", "DB_NAME", "DB_USERNAME")})

def query(sql):
    args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
            "-u", env.get("DB_USERNAME", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", ""), "-e", sql]
    result = subprocess.run(args, capture_output=True, text=True)
    return result.stdout or result.stderr

print(query("SELECT device_id, platform, ultra_runtime_fingerprint, LEFT(ultra_runtime_self_sha256,16) AS self16, updated_at FROM client_device_sessions WHERE client_id=3 ORDER BY updated_at DESC LIMIT 5;"))
print(query("SELECT COUNT(*) AS sessions, SUM(ultra_runtime_fingerprint LIKE '2026.09.13.2%') AS new_fp FROM client_device_sessions WHERE client_id=3;"))
print(query("SELECT id, client_id, LEFT(device_hash,16) AS dev, reason, created_at FROM ultra_candidate_reputation_events ORDER BY id DESC LIMIT 8;") if False else "")
