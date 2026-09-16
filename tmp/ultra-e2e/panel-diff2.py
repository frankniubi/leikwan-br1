import json, pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
local = json.loads((root / "artifacts/versions.json").read_text(encoding="utf-8"))
remote = json.loads((root / ".build/panel-versions.json").read_text(encoding="utf-8"))
print("keys local=%d remote=%d" % (len(local), len(remote)))
print("only-local:", sorted(set(local) - set(remote)))
print("only-remote:", sorted(set(remote) - set(local)))
def brief(e):
    if isinstance(e, dict):
        return "%s sha=%s size=%s vc=%s" % (e.get("version"), str(e.get("sha256"))[:12], e.get("size_bytes"), e.get("version_code"))
    return str(e)[:60]
changed = [k for k in sorted(set(local) & set(remote)) if json.dumps(local[k], sort_keys=True) != json.dumps(remote[k], sort_keys=True)]
print("differing keys: %d" % len(changed))
for k in changed:
    print("  %-34s local[%s] remote[%s]" % (k, brief(local[k]), brief(remote[k])))
