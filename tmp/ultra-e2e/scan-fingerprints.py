import pathlib
import re

root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\artifacts\ultra")
pattern = re.compile(rb"20\d\d\.\d\d\.\d\d\.\d+\.[0-9a-zA-Z_]{2,40}")
for name in ("ultra-client_macos_amd64", "ultra-client_macos_arm64", "ultra-client_linux_arm64",
             "ultra-client_android_arm64", "ultra-client_android_x86_64", "ultra-client_linux_amd64",
             "ultra-client_windows_amd64.exe"):
    path = root / name
    if not path.is_file():
        print("%-34s missing" % name)
        continue
    data = path.read_bytes()
    hits = sorted({m.group(0).decode("ascii", "ignore") for m in pattern.finditer(data)})
    print("%-34s %s" % (name, hits[:6] if hits else "(no version string found)"))
