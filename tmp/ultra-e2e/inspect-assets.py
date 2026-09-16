
import hashlib, pathlib, re, json, datetime
def sha(p):
    h=hashlib.sha256()
    with open(p,'rb') as f:
        for c in iter(lambda: f.read(1<<20), b''): h.update(c)
    return h.hexdigest()
targets = [
 'artifacts/ultra/ultra-client_macos_arm64','artifacts/ultra/ultra-client_macos_amd64',
 'artifacts/ultra/ultra-client_android_arm64','artifacts/ultra/ultra-client_android_x86_64',
 'leikwan-android/app/src/main/assets/runtime/arm64-v8a/ultra-client',
 'leikwan-android/app/src/main/assets/runtime/x86_64/ultra-client',
 'leikwan-android/app/src/main/jniLibs/arm64-v8a/libultra_client.so',
 'leikwan-android/app/src/main/jniLibs/x86_64/libultra_client.so',
 'artifacts/android/leikwan-android-client.apk',
]
for t in targets:
    p=pathlib.Path(t)
    if not p.exists(): print(t,'MISSING'); continue
    d=p.read_bytes()
    m=re.findall(rb'2026\.09\.13\.[0-9]+\.[0-9a-f]{8}', d)
    stamps=sorted(set(x.decode() for x in m))
    m2=re.findall(rb'2026\.09\.1[0-9]\.[0-9]+\.[0-9a-f]{8}', d)
    print(f"{t}\n   size={len(d)} sha256={sha(t)} mtime={datetime.datetime.fromtimestamp(p.stat().st_mtime)}")
    print(f"   stamps={stamps[:6]}")
