import json
import pathlib

vpath = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\artifacts\versions.json")
doc = json.loads(vpath.read_text(encoding="utf-8"))
vpath.write_bytes(json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n")
print("rewritten with LF; bytes:", vpath.stat().st_size)
