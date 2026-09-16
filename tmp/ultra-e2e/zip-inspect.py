
import hashlib, json, plistlib, struct, zipfile, pathlib
def sha(b): return hashlib.sha256(b).hexdigest()
for arch in ("arm64","x64"):
    z = f"artifacts/macos/leikwan-bridge-update-{arch}.zip"
    with zipfile.ZipFile(z) as a:
        names=a.namelist()
        info_name=[n for n in names if n.endswith("Contents/Info.plist")][0]
        pl=plistlib.loads(a.read(info_name))
        rt=a.read("LeiKwan Bridge.app/Contents/Resources/bin/ultra-client")
        seal=plistlib.loads(a.read("LeiKwan Bridge.app/Contents/_CodeSignature/CodeResources"))
        sealed=seal["files2"].get("Resources/bin/ultra-client")
        print(f"{z}: entries={len(names)} version={pl['CFBundleShortVersionString']} runtime_sha={sha(rt)} size={len(rt)}")
        print(f"   sealed hash2 match: {sealed.get('hash2').hex()==sha(rt)}  sealed={sealed.get('hash2').hex()[:16]}")
    # does the published runtime artifact carry a code signature?
    ra = f"artifacts/ultra/ultra-client_macos_{'arm64' if arch=='arm64' else 'amd64'}"
    d = pathlib.Path(ra).read_bytes()
    print(f"   {ra}: sha={sha(d)[:16]} size={len(d)}")
