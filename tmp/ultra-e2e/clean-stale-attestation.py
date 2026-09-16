import hashlib
import json
import pathlib

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
removed = []
kept = []
for key, entry in doc.items():
    if not isinstance(entry, dict):
        continue
    attestation = entry.get("attestation")
    if not isinstance(attestation, dict):
        continue
    version = str(entry.get("version", ""))
    fingerprint = str(attestation.get("fingerprint", ""))
    sha = str(entry.get("sha256", ""))
    self_sha = str(attestation.get("self_sha256", ""))
    if version and fingerprint.startswith(version + ".") and self_sha.lower() == sha.lower():
        kept.append("%s %s" % (key, fingerprint))
        continue
    removed.append("%s: version=%s fingerprint=%s self_sha=%s file_sha=%s" % (
        key, version, fingerprint, self_sha[:16], sha[:16]))
    del entry["attestation"]
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("kept (self-consistent, enforcement can match these):")
for line in kept:
    print("  " + line)
print("removed (stale: fingerprint or hash did not describe the published bytes):")
for line in removed:
    print("  " + line)
