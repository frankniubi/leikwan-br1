import hashlib
import json
import pathlib

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
vpath = root / "artifacts" / "versions.json"
doc = json.loads(vpath.read_text(encoding="utf-8"))
runtime_entry = doc["ultra_client_runtime_windows"]
app_entry = doc["windows_client_app"]
setup = (root / app_entry["path"].replace("/", "\\")).read_bytes()
sha = hashlib.sha256(setup).hexdigest()
app_entry["version"] = "0.1.20.46"
app_entry["sha256"] = sha
app_entry["size_bytes"] = len(setup)
app_entry.setdefault("runtime_sha256", {})["ultra_client_runtime_windows"] = runtime_entry["sha256"]
if app_entry["runtime_sha256"]["ultra_client_runtime_windows"] != runtime_entry["sha256"]:
    raise SystemExit("pin mismatch")
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("app:", app_entry["version"], sha[:16], "pin", runtime_entry["sha256"][:16], "runtime", runtime_entry["version"])
