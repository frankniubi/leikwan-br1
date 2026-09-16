import difflib, pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
for name, local_rel in (("BaseController.php", "master/src/Controllers/BaseController.php"),
                        ("TenantController.php", "master/src/Controllers/TenantController.php")):
    local = (root / local_rel).read_text(encoding="utf-8").splitlines()
    panel = (root / (".build/panel/" + name)).read_text(encoding="utf-8").splitlines()
    diff = list(difflib.unified_diff(panel, local, fromfile="panel/" + name, tofile="local/" + name, lineterm="", n=0))
    added = [l for l in diff if l.startswith("+") and not l.startswith("+++")]
    removed = [l for l in diff if l.startswith("-") and not l.startswith("---")]
    print("=== %s: +%d -%d lines ===" % (name, len(added), len(removed)))
    for l in removed[:10]:
        print("  REMOVED", l[:140])
    for l in added[:6]:
        print("  added", l[:120])
