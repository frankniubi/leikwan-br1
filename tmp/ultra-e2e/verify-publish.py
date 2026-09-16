import hashlib
import json
import pathlib
import subprocess
import urllib.request

root = "/www/wwwroot/v3.leikwanhost.com/leikwan-v3"
for name, path in (("linux runtime", "artifacts/ultra/ultra-client_linux_amd64"), ("agent", "artifacts/client-agent.py")):
    body = urllib.request.urlopen("https://v3.leikwanhost.com/" + path, timeout=60).read()
    served = hashlib.sha256(body).hexdigest()
    local = hashlib.sha256(pathlib.Path(root, path).read_bytes()).hexdigest()
    print("%-14s served=%s local=%s %s" % (name, served[:16], local[:16], "OK" if served == local else "MISMATCH"))

manifest = json.loads(urllib.request.urlopen("https://v3.leikwanhost.com/artifacts/versions.json", timeout=60).read())
for key in ("ultra_client_runtime", "client_agent"):
    entry = manifest[key]
    print("%-24s version=%s sha=%s attestation=%s" % (
        key, entry.get("version"), str(entry.get("sha256"))[:16], json.dumps(entry.get("attestation"))))
published = {}
for key, entry in manifest.items():
    attestation = entry.get("attestation") if isinstance(entry, dict) else None
    if isinstance(attestation, dict) and attestation.get("fingerprint"):
        published.setdefault(attestation["fingerprint"], []).append(str(attestation.get("self_sha256"))[:12])
print("enforcement map:", json.dumps(published, indent=1))
