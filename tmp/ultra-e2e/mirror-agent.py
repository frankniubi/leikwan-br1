import hashlib
import json
import pathlib
import shutil

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
src = root / "client-agent" / "client_agent.py"
mirror = root / "artifacts" / "client-agent.py"
shutil.copyfile(src, mirror)

data = src.read_bytes()
digest = hashlib.sha256(data).hexdigest()
version = None
for line in data.decode("utf-8").splitlines():
    if line.startswith("AGENT_VERSION"):
        version = line.split("=", 1)[1].strip().strip('"')
        break
if not version:
    raise SystemExit("AGENT_VERSION not found")

vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
entry = doc["client_agent"]
before = {k: entry.get(k) for k in ("version", "sha256", "size_bytes")}
entry["version"] = version
entry["sha256"] = digest
entry["size"] = len(data)
entry["size_bytes"] = len(data)
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("before:", before)
print("after :", {k: entry.get(k) for k in ("version", "sha256", "size_bytes")})
