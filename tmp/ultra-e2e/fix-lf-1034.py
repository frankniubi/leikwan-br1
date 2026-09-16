import pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\tmp\ultra-e2e")
for name in ("mac-dmg-1034.sh", "mac-1034-run.sh"):
    path = root / name
    data = path.read_bytes().replace(b"\r\n", b"\n")
    path.write_bytes(data)
    print(name, "CRLF now:", data.count(b"\r\n"), "lines:", data.count(b"\n"))
