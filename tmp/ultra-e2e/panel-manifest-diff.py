import json, urllib.request, pathlib, hashlib
local = json.loads(pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\artifacts\versions.json").read_text(encoding="utf-8"))
remote = json.loads(urllib.request.urlopen("https://v3.leikwanhost.com/artifacts/versions.json", timeout=60).read().decode("utf-8"))
print("keys local=%d remote=%d" % (len(local), len(remote)))
print("only-local:", sorted(set(local) - set(remote)))
print("only-remote:", sorted(set(remote) - set(local)))
changed = []
for k in sorted(set(local) & set(remote)):
    a = json.dumps(local[k], sort_keys=True)
    b = json.dumps(remote[k], sort_keys=True)
    if a != b:
        changed.append(k)
print("differing keys (%d):" % len(changed))
for k in changed:
    la = local[k]; rb = remote[k]
    def brief(e):
        if isinstance(e, dict):
            return "%s sha=%s size=%s vc=%s" % (e.get("version"), str(e.get("sha256"))[:12], e.get("size_bytes"), e.get("version_code"))
        return str(e)[:60]
    print("  %-34s local[%s] remote[%s]" % (k, brief(la), brief(rb)))
