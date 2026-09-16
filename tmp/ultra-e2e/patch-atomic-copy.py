import pathlib
p = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\tmp\ultra-e2e\stage-headless-manifest.py")
t = p.read_text(encoding="utf-8")
helper = '''
def atomic_copy(source, destination):
    """Copy through a scratch file: some Windows filter drivers refuse an
    in-place truncating open of an artifact that was just downloaded."""
    scratch = destination.with_suffix(destination.suffix + ".incoming")
    with open(source, "rb") as src, open(scratch, "wb") as dst:
        for chunk in iter(lambda: src.read(1 << 20), b""):
            dst.write(chunk)
    scratch.replace(destination)
'''
t = t.replace('doc = json.loads(VERSIONS.read_text(encoding="utf-8"))', helper.strip() + '\n\ndoc = json.loads(VERSIONS.read_text(encoding="utf-8"))', 1)
t = t.replace("shutil.copyfile(src, dest)", "atomic_copy(src, dest)")
t = t.replace("shutil.copyfile(dmg_src, dmg_dest)", "atomic_copy(dmg_src, dmg_dest)")
p.write_text(t, encoding="utf-8")
print("patched to atomic copies:", t.count("atomic_copy("))
