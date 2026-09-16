import concurrent.futures
import hashlib
import json
import urllib.request
from pathlib import Path


BASE = "https://v3.leikwanhost.com"
ROOT = Path(__file__).resolve().parents[2]
doc = json.loads((ROOT / "artifacts" / "versions.json").read_text())
keys = [
    "ultra_client_runtime",
    "ultra_client_runtime_linux_arm64",
    "ultra_client_runtime_windows",
    "ultra_client_runtime_macos_arm64",
    "ultra_client_runtime_macos_x64",
    "macos_client_app_arm64",
    "macos_client_app_x64",
    "macos_client_update_arm64",
    "macos_client_update_x64",
    "windows_headless_client",
    "macos_headless_installer",
    "macos_headless_uninstaller",
    "windows_headless_installer",
    "windows_headless_uninstaller",
]


def fetch(key: str) -> tuple[str, int, str]:
    entry = doc[key]
    url = BASE + (entry.get("artifact_url") or entry.get("install_url"))
    request = urllib.request.Request(url, headers={"User-Agent": "LeikwanBridge/2026.09.16.1"})
    digest = hashlib.sha256()
    size = 0
    with urllib.request.urlopen(request, timeout=180) as response:
        while True:
            chunk = response.read(1 << 20)
            if not chunk:
                break
            digest.update(chunk)
            size += len(chunk)
    expected = entry["sha256"]
    status = "OK" if digest.hexdigest() == expected and size == entry["size_bytes"] else "MISMATCH"
    return key, size, status


with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    results = list(pool.map(fetch, keys))
for key, size, status in results:
    print(f"{key}: {status} {size}")
if any(status != "OK" for _, _, status in results):
    raise SystemExit(1)
manifest_request = urllib.request.Request(BASE + "/artifacts/versions.json", headers={"User-Agent": "LeikwanBridge/2026.09.16.1"})
live = json.loads(urllib.request.urlopen(manifest_request, timeout=60).read())
assert live["ultra_client_runtime"]["version"] == "2026.09.16.1"
assert live["ultra_client_runtime_windows"]["version"] == "2026.09.16.1"
assert live["macos_client_update_arm64"]["version"] == "1.0.40"
assert live["macos_client_update_x64"]["version"] == "1.0.40"
assert live["windows_headless_client"]["version"] == "0.1.20.49"
print("live-manifest: OK")
