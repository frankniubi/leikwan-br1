import pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\master\resources\knowledgebase")
fixed = []
for locale in ("en", "zh-Hans", "zh-TW"):
    path = root / locale / "no-gui-clients.html"
    text = path.read_text(encoding="utf-8")
    for prefix in (" accTitle:", " accDescr:"):
        if prefix in text:
            text = text.replace(prefix, prefix.lstrip())
            fixed.append(f"{locale}:{prefix.strip()}")
    path.write_text(text, encoding="utf-8")
print("stripped leading spaces:", fixed)
