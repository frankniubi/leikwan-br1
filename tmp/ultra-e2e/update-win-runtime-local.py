import hashlib
import json
import pathlib

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
entry = doc["ultra_client_runtime_windows"]
data = (root / entry["path"].replace("/", "\\")).read_bytes()
sha = hashlib.sha256(data).hexdigest()
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
entry["version"] = "2026.09.13.3"
entry["sha256"] = sha
entry["size_bytes"] = len(data)
entry["build"] = "go1.26.5, cgo-disabled, stripped, unobfuscated (Windows Defender false positive), fast-path-failover-2026.09.13.3"
entry["attestation"] = {"fingerprint": "2026.09.13.3.452c8fe8", "self_sha256": sha}
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("windows runtime before:", before)
print("windows runtime after :", {k: entry.get(k) for k in ("version", "sha256", "size_bytes")})
print("attestation:", entry["attestation"])
print("app pin (must be updated by the app publisher):", doc["windows_client_app"].get("runtime_sha256", {}).get("ultra_client_runtime_windows")[:16])
