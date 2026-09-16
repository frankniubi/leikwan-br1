import pathlib
p = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\tmp\ultra-e2e\stage-headless-manifest.py")
t = p.read_text(encoding="utf-8")
old = 'VERSIONS.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\\n")'
new = ('payload = json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\\n"\n'
       'scratch = VERSIONS.with_suffix(".json.stage")\n'
       'scratch.write_bytes(payload)\n'
       'json.loads(scratch.read_text(encoding="utf-8"))\n'
       'scratch.replace(VERSIONS)')
assert old in t, "anchor not found"
p.write_text(t.replace(old, new), encoding="utf-8")
print("patched staging writer to temp+replace")
