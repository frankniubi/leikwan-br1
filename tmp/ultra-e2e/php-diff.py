import difflib, pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
for name, local_rel, panel_rel in (
    ("Bootstrap.php", "master/src/Bootstrap.php", ".build/panel/Bootstrap.php"),
    ("ArtifactController.php", "master/src/Controllers/ArtifactController.php", ".build/panel/ArtifactController.php"),
):
    local = (root / local_rel).read_text(encoding="utf-8").splitlines()
    panel = (root / panel_rel).read_text(encoding="utf-8").splitlines()
    diff = list(difflib.unified_diff(panel, local, fromfile="panel/" + name, tofile="local/" + name, lineterm="", n=1))
    print("=== %s: %d diff lines ===" % (name, len(diff)))
    print(chr(10).join(diff[:60]))
