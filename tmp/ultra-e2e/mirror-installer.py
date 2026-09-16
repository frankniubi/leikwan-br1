import hashlib
import json
import pathlib
import re

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
src = root / "install" / "install-client-agent.sh"
data = src.read_bytes()
match = re.search(r'INSTALLER_VERSION="([^"]+)"', data.decode("utf-8"))
if not match:
    raise SystemExit("INSTALLER_VERSION not found")
version = match.group(1)
digest = hashlib.sha256(data).hexdigest()
vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
entry = doc["client_agent_installer"]
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
entry["version"] = version
entry["sha256"] = digest
entry["size"] = len(data)
entry["size_bytes"] = len(data)
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("installer before:", before)
print("installer after :", {k: entry.get(k) for k in ("version", "sha256", "size_bytes")})
