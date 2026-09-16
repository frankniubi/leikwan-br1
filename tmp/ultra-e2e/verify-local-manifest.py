import hashlib, json, pathlib, zipfile
ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
doc = json.loads((ROOT / "artifacts/versions.json").read_text(encoding="utf-8"))
def sha(p):
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for c in iter(lambda: f.read(1 << 20), b""): h.update(c)
    return h.hexdigest()
problems = []
for key, entry in doc.items():
    if not isinstance(entry, dict):
        continue
    rel = entry.get("path")
    if not rel:
        continue
    path = ROOT / rel
    if not path.is_file():
        problems.append("%s: file missing (%s)" % (key, rel)); continue
    if entry.get("sha256") and sha(path) != entry["sha256"]:
        problems.append("%s: hash mismatch" % key)
    if entry.get("size_bytes") and path.stat().st_size != entry["size_bytes"]:
        problems.append("%s: size mismatch" % key)
for arch, key in (("arm64", "macos_client_update_arm64"), ("x64", "macos_client_update_x64")):
    with zipfile.ZipFile(ROOT / doc[key]["path"]) as z:
        embedded = hashlib.sha256(z.read("LeiKwan Bridge.app/Contents/Resources/bin/ultra-client")).hexdigest()
    published = doc["ultra_client_runtime_macos_%s" % ("arm64" if arch == "arm64" else "x64")]["sha256"]
    if embedded != published:
        problems.append("%s: embedded runtime %s != published %s" % (key, embedded[:16], published[:16]))
    else:
        print("%-28s embedded runtime == published (%s)" % (key, embedded[:16]))
print("mac runtime attestations:", json.dumps({k: doc[k]["attestation"] for k in ("ultra_client_runtime_macos_arm64", "ultra_client_runtime_macos_x64")}))
print("mac app version:", doc["macos_client_app_arm64"]["version"], doc["macos_client_app_x64"]["version"])
print("headless entries:", [k for k in doc if "headless" in k])
print("PROBLEMS:", problems if problems else "none")
