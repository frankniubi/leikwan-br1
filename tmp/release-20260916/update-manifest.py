import hashlib
import json
from pathlib import Path

root = Path(__file__).resolve().parents[2]
manifest_path = root / "artifacts" / "versions.json"
runtime_version = "2026.09.16.1"
fingerprint = "2026.09.16.1.e0e4a71f"
mac_app_version = "1.0.40"
windows_headless_version = "0.1.20.49"


def meta(relative_path: str) -> tuple[str, int]:
    path = root / relative_path
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    return digest, path.stat().st_size


def set_artifact(entry: dict, relative_path: str) -> tuple[str, int]:
    digest, size = meta(relative_path)
    entry["sha256"] = digest
    entry["size_bytes"] = size
    if "size" in entry:
        entry["size"] = size
    return digest, size


def set_runtime(entry: dict, relative_path: str, build: str, obfuscator: str) -> None:
    digest, _ = set_artifact(entry, relative_path)
    entry["version"] = runtime_version
    entry["build"] = build
    entry["obfuscator"] = obfuscator
    entry["attestation"] = {
        "fingerprint": fingerprint,
        "self_sha256": digest,
    }


def helper_map(report_name: str) -> dict[str, str]:
    report = json.loads((Path(__file__).parent / "mac-out" / report_name).read_text())
    return {helper["path"]: helper["sha256"] for helper in report["helpers"].values()}


doc = json.loads(manifest_path.read_text(encoding="utf-8-sig"))
linux_build = "go1.26.5, cgo-disabled, stripped, garble-v0.17.0, active-candidate-fallback-2026.09.16.1"
windows_build = "go1.26.5, cgo-disabled, stripped, unobfuscated (Windows Defender clean), active-candidate-fallback-2026.09.16.1"
mac_build = "go1.26.5, cgo-disabled, stripped, garble-v0.17.0, rcodesign-0.29.0, active-candidate-fallback-2026.09.16.1"
set_runtime(doc["ultra_client_runtime"], "artifacts/ultra/ultra-client_linux_amd64", linux_build, "garble-v0.17.0")
set_runtime(doc["ultra_client_runtime_linux_arm64"], "artifacts/ultra/ultra-client_linux_arm64", linux_build, "garble-v0.17.0")
set_runtime(doc["ultra_client_runtime_windows"], "artifacts/ultra/ultra-client_windows_amd64.exe", windows_build, "none")
set_runtime(doc["ultra_client_runtime_macos_arm64"], "artifacts/ultra/ultra-client_macos_arm64", mac_build, "garble-v0.17.0")
set_runtime(doc["ultra_client_runtime_macos_x64"], "artifacts/ultra/ultra-client_macos_amd64", mac_build, "garble-v0.17.0")

for key in ("macos_client_app_arm64", "macos_client_update_arm64"):
    entry = doc[key]
    entry["version"] = mac_app_version
    set_artifact(entry, entry["path"])
    entry["runtime_sha256"] = helper_map("report-arm64.json")
for key in ("macos_client_app_x64", "macos_client_update_x64"):
    entry = doc[key]
    entry["version"] = mac_app_version
    set_artifact(entry, entry["path"])
    entry["runtime_sha256"] = helper_map("report-x64.json")

entry = doc["windows_headless_client"]
entry["version"] = windows_headless_version
set_artifact(entry, entry["path"])
for key in ("macos_headless_installer", "macos_headless_uninstaller"):
    entry = doc[key]
    entry["version"] = mac_app_version
    set_artifact(entry, entry["path"])
for key in ("windows_headless_installer", "windows_headless_uninstaller"):
    entry = doc[key]
    entry["version"] = windows_headless_version
    set_artifact(entry, entry["path"])

manifest_path.write_text(json.dumps(doc, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="")
print(f"updated {manifest_path}")
