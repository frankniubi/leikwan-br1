import pathlib
p = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\tmp\ultra-e2e\stage-headless-manifest.py")
t = p.read_text(encoding="utf-8")
old = '''def atomic_copy(source, destination):'''
new = '''def atomic_copy(source, destination):
    if destination.is_file() and destination.stat().st_size == source.stat().st_size:
        import hashlib as _h
        def _sha(path):
            h = _h.sha256()
            with open(path, "rb") as fh:
                for chunk in iter(lambda: fh.read(1 << 20), b""): h.update(chunk)
            return h.hexdigest()
        if _sha(source) == _sha(destination):
            return  # already staged: never rewrite a large artifact under a scanner
'''
assert old in t
p.write_text(t.replace(old, new, 1), encoding="utf-8")
print("staging is now idempotent")
