#!/usr/bin/env python3
import hashlib, json, os, shutil, time

ROOT = "/www/wwwroot/v3.leikwanhost.com/leikwan-v3"
ART = os.path.join(ROOT, "artifacts/ultra/ultra-client_linux_amd64")
NEW = "/root/ultra-client_linux_amd64.new"
VERSIONS = os.path.join(ROOT, "artifacts/versions.json")
EXPECT_SHA = "835ffd941596ff7f9717591051819fc2de78e2859298164a40af7a41a21ae977"
VERSION = "2026.09.13.3"
FINGERPRINT = "2026.09.13.3.452c8fe8"
BUILD = "go1.26.5, cgo-disabled, stripped, garble-v0.17.0, fast-path-failover-2026.09.13.3"

data = open(NEW, "rb").read()
sha = hashlib.sha256(data).hexdigest()
size = len(data)
if sha != EXPECT_SHA:
    raise SystemExit("sha mismatch: %s != %s" % (sha, EXPECT_SHA))
if data[:6] != b"\x7fELF\x02\x01":
    raise SystemExit("not ELF64 LE")
if os.path.exists(ART) and hashlib.sha256(open(ART, "rb").read()).hexdigest() == sha:
    print("artifact already current")

stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
backup = "/root/leikwan-br1-backups/ultra-linux-%s" % stamp
os.makedirs(backup, exist_ok=True)
if os.path.exists(ART):
    shutil.copy2(ART, os.path.join(backup, "ultra-client_linux_amd64.prev"))
shutil.copy2(VERSIONS, os.path.join(backup, "versions.json.prev"))

shutil.copy2(NEW, ART)
os.chmod(ART, 0o755)
print("installed %s bytes=%d sha=%s" % (ART, size, sha))

doc = json.load(open(VERSIONS))
entry = doc["ultra_client_runtime"]
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
entry["version"] = VERSION
entry["sha256"] = sha
entry["size_bytes"] = size
entry["build"] = BUILD
entry["obfuscator"] = "garble-v0.17.0"
entry["attestation"] = {"fingerprint": FINGERPRINT, "self_sha256": sha}
tmp = VERSIONS + ".new"
with open(tmp, "w") as handle:
    json.dump(doc, handle, indent=4, ensure_ascii=False)
    handle.write("\n")
json.load(open(tmp))  # validate
os.replace(tmp, VERSIONS)
print("manifest before:", json.dumps(before, sort_keys=True))
print("manifest after :", json.dumps({k: entry.get(k) for k in ("version", "sha256", "size_bytes")}, sort_keys=True))
print("backup:", backup)
