import pathlib
import subprocess
import time

time.sleep(100)

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
    "SELECT COUNT(*) total, SUM(ultra_runtime_fingerprint IS NOT NULL) with_fp, SUM(ultra_runtime_attested_at IS NOT NULL) attested FROM client_device_sessions;",
    "SELECT client_id, platform, ultra_runtime_fingerprint, LEFT(ultra_runtime_self_sha256,16) self16, ultra_runtime_debugger dbg, ultra_runtime_instrumented inst, ultra_runtime_attested_at FROM client_device_sessions WHERE ultra_runtime_fingerprint IS NOT NULL ORDER BY updated_at DESC LIMIT 10;",
):
    out = subprocess.run(args + ["-e", sql], capture_output=True, text=True)
    print(out.stdout or out.stderr)

manifest = __import__("json").loads(pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json").read_text(encoding="utf-8"))
released = {}
for key, entry in manifest.items():
    attest = entry.get("attestation") if isinstance(entry, dict) else None
    if isinstance(attest, dict) and attest.get("fingerprint"):
        released.setdefault(str(attest["fingerprint"]).lower(), []).append(str(attest.get("self_sha256", "")).lower())
print("published fingerprints:", {k: len(v) for k, v in released.items()})

rows = subprocess.run(args + ["-N", "-e",
    "SELECT platform, ultra_runtime_fingerprint, LOWER(ultra_runtime_self_sha256) FROM client_device_sessions WHERE ultra_runtime_fingerprint IS NOT NULL;"],
    capture_output=True, text=True).stdout
print("per-device verification:")
for line in rows.strip().splitlines():
    parts = line.split("\t")
    if len(parts) != 3:
        continue
    platform, fingerprint, digest = parts
    allowed = released.get(fingerprint.lower(), [])
    verdict = "MATCH" if digest in allowed else ("UNKNOWN_FINGERPRINT" if not allowed else "MISMATCH(would be refused under enforce)")
    print("  %-18s %-28s %s" % (platform, fingerprint, verdict))
