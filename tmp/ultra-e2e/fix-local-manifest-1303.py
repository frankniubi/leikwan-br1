import hashlib
import json
import pathlib

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
artifact = root / "artifacts" / "ultra" / "ultra-client_linux_amd64"
data = artifact.read_bytes()
digest = hashlib.sha256(data).hexdigest()
vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
entry = doc["ultra_client_runtime"]
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
entry["version"] = "2026.09.13.3"
entry["sha256"] = digest
entry["size_bytes"] = len(data)
entry["build"] = "go1.26.5, cgo-disabled, stripped, garble-v0.17.0, fast-path-failover-2026.09.13.3"
entry["obfuscator"] = "garble-v0.17.0"
entry["attestation"] = {"fingerprint": "2026.09.13.3.452c8fe8", "self_sha256": digest}
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("before:", before)
print("after :", {k: entry.get(k) for k in ("version", "sha256", "size_bytes")})
