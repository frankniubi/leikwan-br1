"""Repackage a macOS client update zip with a new ultra-client runtime.

Mirrors the historical Windows-side macOS release pipeline
(.tmp/mac-android-release-20260906/sign-mac.py + audit-package-mac.py):
  1. extract the committed update zip
  2. replace Contents/Resources/bin/ultra-client with the freshly built runtime
  3. ad-hoc re-sign the whole bundle with rcodesign twice, refreshing
     helper-manifest.json hashes between passes (signing rewrites Mach-O bytes)
     and asserting signature determinism
  4. rebuild the zip preserving every original entry's Unix metadata
  5. audit: helper manifest, seal hash2 for bin/ultra-client, Mach-O headers,
     zip integrity and byte equality of the embedded runtime.
"""
import argparse
import hashlib
import json
import plistlib
import struct
import subprocess
import sys
import zipfile
from pathlib import Path

APP_NAME = "LeiKwan Bridge.app"
CPU = {"arm64": 0x0100000C, "x64": 0x01000007}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input-zip", required=True, type=Path)
    parser.add_argument("--runtime", required=True, type=Path)
    parser.add_argument("--rcodesign", required=True, type=Path)
    parser.add_argument("--arch", required=True, choices=("arm64", "x64"))
    parser.add_argument("--output-zip", required=True, type=Path)
    parser.add_argument("--stage", required=True, type=Path)
    parser.add_argument("--expected-version", required=True)
    parser.add_argument("--signed-artifact", required=True, type=Path)
    args = parser.parse_args()

    stage = args.stage.resolve()
    if stage.exists():
        import shutil
        shutil.rmtree(stage)
    stage.mkdir(parents=True)

    with zipfile.ZipFile(args.input_zip) as source:
        infos = source.infolist()
        for info in infos:
            name = info.filename
            target = (stage / name).resolve()
            if stage not in target.parents and target != stage:
                raise SystemExit(f"entry escapes stage: {name}")
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(source.read(info))
    print(f"extracted {len(infos)} entries", flush=True)

    app = stage / APP_NAME
    contents = app / "Contents"
    if not app.is_dir():
        raise SystemExit("app bundle missing in zip")
    plist = plistlib.loads((contents / "Info.plist").read_bytes())
    if plist.get("CFBundleIdentifier") != "com.leikwan.bridge":
        raise SystemExit("bundle identifier mismatch")
    if plist.get("CFBundleShortVersionString") != args.expected_version:
        raise SystemExit("bundle version mismatch")

    runtime_in_bundle = contents / "Resources" / "bin" / "ultra-client"
    if not runtime_in_bundle.is_file():
        raise SystemExit("embedded ultra-client missing")
    new_runtime = args.runtime.read_bytes()
    unsigned_runtime_sha = sha256(args.runtime)
    runtime_in_bundle.write_bytes(new_runtime)
    print(f"replaced runtime (unsigned): sha256={unsigned_runtime_sha} size={len(new_runtime)}", flush=True)

    manifest_path = contents / "Resources" / "helper-manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest.get("architecture") != ("arm64" if args.arch == "arm64" else "x86_64"):
        raise SystemExit("helper manifest architecture mismatch")
    for helper in manifest["helpers"]:
        if helper.get("name") == "ultra-client":
            helper["sha256"] = unsigned_runtime_sha
            helper["size_bytes"] = len(new_runtime)
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")

    rcodesign = str(args.rcodesign.resolve())
    previous_manifest = None
    for run in range(2):
        log = stage / f"rcodesign-{args.arch}-{run}.log"
        with log.open("wb") as stream:
            result = subprocess.run(
                [rcodesign, "sign", "--timestamp-url", "none", str(app)],
                stdout=stream, stderr=subprocess.STDOUT)
        if result.returncode:
            tail = log.read_bytes()[-4000:].decode(errors="replace")
            raise SystemExit(f"rcodesign failed (run {run}): {tail}")
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        for helper in manifest["helpers"]:
            path = contents / "Resources" / helper["path"]
            helper["sha256"] = sha256(path)
            helper["size_bytes"] = path.stat().st_size
        payload = json.dumps(manifest, indent=2) + "\n"
        if run == 0:
            previous_manifest = payload
        elif payload != previous_manifest:
            raise SystemExit("non-deterministic helper signatures"
                             f" (run {run} manifest differs from run 0)")
        manifest_path.write_text(payload, encoding="utf-8")
        print(f"signed pass {run}: rcodesign ok", flush=True)

    # Code-signing rewrites Mach-O bytes, so the bundle-embedded runtime is only
    # comparable to the runtime artifact after signing.  The final published
    # artifact for macOS is the signed bundle binary (see --signed-artifact).
    signed_runtime_sha = sha256(runtime_in_bundle)
    print(f"signed runtime (bundle): sha256={signed_runtime_sha} size={runtime_in_bundle.stat().st_size}", flush=True)
    seal_path = contents / "_CodeSignature" / "CodeResources"
    seal = plistlib.loads(seal_path.read_bytes())
    sealed = seal.get("files2", {}).get("Resources/bin/ultra-client")
    if not sealed or sealed.get("hash2") != bytes.fromhex(signed_runtime_sha):
        raise SystemExit("seal does not cover bin/ultra-client with the new hash")
    print("seal hash2 for bin/ultra-client matches signed runtime", flush=True)

    apphost = contents / "MacOS" / plist["CFBundleExecutable"]
    header = apphost.read_bytes()[:8]
    if len(header) != 8 or struct.unpack("<II", header) != (0xFEEDFACF, CPU[args.arch]):
        raise SystemExit("apphost architecture mismatch")
    other = [p for p in (contents / "MacOS").iterdir() if p != apphost]
    if other:
        raise SystemExit("MacOS directory must contain only the apphost")

    helpers = manifest["helpers"]
    if any(h["name"] == "ultra-client" for h in helpers) is False:
        raise SystemExit("ultra-client missing from helper manifest")
    for helper in helpers:
        path = contents / "Resources" / helper["path"]
        if sha256(path) != helper["sha256"] or path.stat().st_size != helper["size_bytes"]:
            raise SystemExit(f"helper manifest mismatch after signing: {helper['name']}")
        magic = path.read_bytes()[:4]
        if magic != b"\xcf\xfa\xed\xfe":
            raise SystemExit(f"helper lost Mach-O magic: {helper['name']}")
    print(f"helper manifest self-consistent ({len(helpers)} helpers)", flush=True)

    args.signed_artifact.parent.mkdir(parents=True, exist_ok=True)
    args.signed_artifact.write_bytes(runtime_in_bundle.read_bytes())
    print(f"signed artifact written: {args.signed_artifact} sha256={signed_runtime_sha}", flush=True)

    names = [info.filename for info in infos]
    with zipfile.ZipFile(args.output_zip, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as out:
        for name in names:
            original = next(i for i in infos if i.filename == name)
            info = zipfile.ZipInfo(name, original.date_time)
            info.create_system = original.create_system
            info.external_attr = original.external_attr
            info.compress_type = original.compress_type
            if original.is_dir():
                out.writestr(info, b"")
            else:
                out.writestr(info, (stage / name).read_bytes())
    print(f"zip rebuilt with {len(names)} entries", flush=True)

    with zipfile.ZipFile(args.output_zip) as out:
        assert out.testzip() is None, "zip integrity check failed"
        assert len(out.infolist()) == len(infos), "zip entry count changed"
        embedded = out.read(f"{APP_NAME}/Contents/Resources/bin/ultra-client")
        assert sha256_bytes(embedded) == signed_runtime_sha, "embedded runtime hash mismatch"
        assert not any(i.filename.startswith("__MACOSX/") for i in out.infolist()), "__MACOSX leaked"
    print("zip audit passed", flush=True)

    report = {
        "architecture": args.arch,
        "bundle_version": plist["CFBundleShortVersionString"],
        "runtime_sha256_unsigned": unsigned_runtime_sha,
        "runtime_sha256_signed": signed_runtime_sha,
        "runtime_size_bytes": runtime_in_bundle.stat().st_size,
        "zip_entries": len(names),
        "zip_sha256": sha256(args.output_zip),
        "zip_size_bytes": args.output_zip.stat().st_size,
        "helpers": {
            h["name"]: {"sha256": h["sha256"], "size_bytes": h["size_bytes"], "path": h["path"]}
            for h in helpers
        },
        "signature": "ad-hoc (rcodesign 0.29.0), not Developer ID or notarized",
    }
    print(json.dumps(report, indent=2))
    return 0


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


if __name__ == "__main__":
    raise SystemExit(main())
