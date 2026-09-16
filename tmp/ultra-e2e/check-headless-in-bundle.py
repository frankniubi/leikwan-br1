import pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
for rel in (".build/mac-1033/publish-osx-arm64/LeikwanBridge.Mac.dll", ".build/mac-1033/stage-arm64/LeiKwan Bridge.app/Contents/Resources/dotnet/LeikwanBridge.Mac.dll"):
    p = root / rel
    if not p.is_file():
        print("missing", rel); continue
    d = p.read_bytes()
    print(rel, "size", len(d))
    for needle in ("headless_start", "headless_connect_failed", "MacHeadlessHost", "--headless"):
        ascii_hit = d.count(needle.encode("ascii"))
        utf16_hit = d.count(needle.encode("utf-16-le"))
        print("   %-24s ascii=%d utf16=%d" % (needle, ascii_hit, utf16_hit))
