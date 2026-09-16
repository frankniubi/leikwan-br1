import pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\tmp\ultra-e2e")
for src, dst in (("mac-dmg-1033.sh", "mac-dmg-1034.sh"), ("mac-1033-run.sh", "mac-1034-run.sh")):
    text = (root / src).read_text(encoding="utf-8")
    text = text.replace("1033", "1034").replace("1.0.33", "1.0.34").replace("u33-", "u34-").replace("b33-", "b34-")
    (root / dst).write_text(text, encoding="utf-8")
    print("wrote", dst)
