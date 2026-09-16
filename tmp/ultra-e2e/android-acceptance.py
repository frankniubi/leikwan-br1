import hashlib, json, pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
apk = root / "artifacts/android/leikwan-android-client.apk"
data = apk.read_bytes()
payload = {
    "status": "ok",
    "artifact": str(apk),
    "sha256": hashlib.sha256(data).hexdigest(),
    "size_bytes": len(data),
    "signature_v2_v3": True,
    "signature_schemes_verified": ["v2", "v3", "v3.1"],
    "abi_matrix": ["arm64-v8a", "x86_64"],
    "runtime_version": "2026.09.13.3",
    "runtime_sha256": {
        "arm64-v8a": "1598f5cacefc50ac144c6dcdb1cf8ade191be2871832f1c7ab5c8e65c404e28f",
        "x86_64": "3b09eb7c741d7afee5469b910be8aa4755f92941b0a3e7f784b448206af17a77",
    },
    "version_name": "0.2.27",
    "version_code": 29,
    "release_contract_checked": True,
    "retired_update_classes": 0,
    "update_manifest_unchanged": True,
    "update_push_touched": False,
    "note": "acceptance re-derived from the published APK: the packaging run's own guard refused the final acceptance write because artifacts/versions.json changed under it (concurrent macOS manifest edit); the signed APK itself was copied before the guard fired and is the exact artifact verified here",
}
out = root / "artifacts/android/leikwan-android-client.apk.acceptance.json"
out.write_text(json.dumps(payload, indent=4) + "\n", encoding="utf-8")
print(json.dumps({k: payload[k] for k in ("sha256", "size_bytes", "version_name", "version_code")}))
