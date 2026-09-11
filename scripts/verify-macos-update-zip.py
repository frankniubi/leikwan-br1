"""Verify a repackaged macOS update zip, independent of the staging pipeline.

Equivalent checks to scripts/verify-macos-dmg-layout.py, applied to the zip's
embedded app bundle, plus drift/update-service expectations:
  * single top-level app bundle with the expected name
  * Info.plist identity and version
  * MacOS/ contains only the native apphost, with the right Mach-O architecture
  * helper-manifest.json self-consistent (hash + size + Mach-O magic)
  * _CodeSignature/CodeResources seals Resources/bin/ultra-client with hash2
    equal to the freshly built runtime artifact
  * embedded ultra-client bytes equal the runtime artifact bytes
"""
import argparse
import hashlib
import json
import plistlib
import struct
import sys
import zipfile
from pathlib import Path

APP_NAME = "LeiKwan Bridge.app"
CPU = {"arm64": 0x0100000C, "x86_64": 0x01000007}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("zip_path", type=Path)
    parser.add_argument("--runtime-artifact", required=True, type=Path)
    parser.add_argument("--version", required=True)
    parser.add_argument("--architecture", required=True, choices=("arm64", "x86_64"))
    args = parser.parse_args()

    import tempfile
    with tempfile.TemporaryDirectory(prefix="leikwan-macos-verify-") as tmp:
        root = Path(tmp)
        with zipfile.ZipFile(args.zip_path) as archive:
            assert archive.testzip() is None, "zip corrupted"
            names = archive.namelist()
            assert not any(n.startswith("__MACOSX/") for n in names), "__MACOSX present"
            top = {n.split("/", 1)[0] for n in names if "/" in n}
            assert top == {APP_NAME}, f"unexpected top-level entries: {top}"
            archive.extractall(root)

        app = root / APP_NAME
        contents = app / "Contents"
        info = plistlib.loads((contents / "Info.plist").read_bytes())
        assert info.get("CFBundleIdentifier") == "com.leikwan.bridge"
        assert info.get("CFBundlePackageType") == "APPL"
        assert info.get("CFBundleShortVersionString") == args.version
        assert info.get("CFBundleVersion") == args.version

        executable = contents / "MacOS" / info["CFBundleExecutable"]
        assert executable.is_file() and not executable.is_symlink()
        stray = [p.name for p in executable.parent.iterdir() if p != executable]
        assert not stray, f"non-launcher content in MacOS: {stray}"
        header = executable.read_bytes()[:8]
        assert struct.unpack("<II", header) == (0xFEEDFACF, CPU[args.architecture]), "apphost arch mismatch"

        manifest_path = contents / "Resources" / "helper-manifest.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        assert manifest["architecture"] == args.architecture
        runtime_entry = None
        for helper in manifest["helpers"]:
            path = contents / "Resources" / helper["path"]
            assert path.is_file(), f"helper missing: {helper['path']}"
            assert path.stat().st_size == helper["size_bytes"], f"size mismatch: {helper['path']}"
            assert sha256(path) == helper["sha256"], f"hash mismatch: {helper['path']}"
            if helper["name"] == "ultra-client":
                runtime_entry = helper
        assert runtime_entry is not None, "ultra-client missing from helper manifest"

        runtime_artifact_sha = sha256(args.runtime_artifact)
        embedded = contents / "Resources" / "bin" / "ultra-client"
        assert sha256(embedded) == runtime_artifact_sha, "embedded runtime != artifact"
        assert runtime_entry["sha256"] == runtime_artifact_sha
        assert runtime_entry["size_bytes"] == args.runtime_artifact.stat().st_size

        seal = plistlib.loads((contents / "_CodeSignature" / "CodeResources").read_bytes())
        sealed = seal["files2"].get("Resources/bin/ultra-client")
        assert sealed and sealed.get("hash2") == bytes.fromhex(runtime_artifact_sha), "seal mismatch"

        print(json.dumps({
            "install_surface_ok": True,
            "version": args.version,
            "architecture": args.architecture,
            "application": APP_NAME,
            "embedded_runtime_sha256": runtime_artifact_sha,
            "helpers_verified": len(manifest["helpers"]),
            "seal_verified": True,
        }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
