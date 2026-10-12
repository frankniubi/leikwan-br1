"""Assemble, ad-hoc sign and repackage the macOS client bundle from Windows.

Mirrors the historical Windows-side macOS release pipeline
(.tmp/mac-android-release-20260906/sign-mac.py) plus the 2026-09-11
repackage-macos-update.py flow, extended so the managed application payload can
change as well (dotnet publish output), not just the embedded ultra-client
runtime:

  1. extract the committed base update zip (bundle shape, entry metadata)
  2. replace Contents/Resources/dotnet/* and Contents/MacOS/<apphost> with the
     fresh self-contained publish output
  3. replace Contents/Resources/bin/ultra-client with the published runtime
  4. bump Info.plist CFBundleShortVersionString/CFBundleVersion
  5. ad-hoc re-sign the whole bundle with rcodesign twice, refreshing
     helper-manifest.json hashes between passes (signing rewrites Mach-O bytes)
     and asserting signature determinism
  6. rebuild the zip preserving every original entry's Unix metadata and order
  7. audit: helper manifest, seal hash2 for bin/ultra-client, apphost Mach-O
     architecture, zip integrity, embedded runtime byte equality

The final published darwin runtime artifact is the bundle-signed binary, so the
embedded runtime MUST stay byte-identical to artifacts/ultra/ultra-client_macos_*.
"""
import argparse
import hashlib
import json
import plistlib
import shutil
import stat
import struct
import subprocess
import sys
import zipfile
from pathlib import Path

APP_NAME = "LeiKwan Bridge.app"
# The macOS client ships for Apple Silicon only.
CPU = {"arm64": 0x0100000C}
HELPERS = ("sing-box", "wstunnel", "leikwan-bond", "leikwan-ingress",
           "leikwan-uot-bond", "ultra-client")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def write_manifest(path: Path, manifest: dict) -> str:
    payload = json.dumps(manifest, indent=2) + "\n"
    path.write_bytes(payload.encode("utf-8"))
    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-zip", required=True, type=Path)
    parser.add_argument("--publish-dir", required=True, type=Path)
    parser.add_argument("--runtime", required=True, type=Path)
    parser.add_argument("--rcodesign", required=True, type=Path)
    parser.add_argument("--arch", required=True, choices=("arm64",),
                        help="arm64 only: the macOS client supports Apple Silicon only")
    parser.add_argument("--version", required=True)
    parser.add_argument("--stage", required=True, type=Path)
    parser.add_argument("--output-zip", required=True, type=Path)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()

    stage = args.stage.resolve()
    if stage.exists():
        shutil.rmtree(stage)
    stage.mkdir(parents=True)

    with zipfile.ZipFile(args.base_zip) as source:
        infos = source.infolist()
        for info in infos:
            target = (stage / info.filename).resolve()
            if stage not in target.parents and target != stage:
                raise SystemExit(f"entry escapes stage: {info.filename}")
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(source.read(info))
    print(f"extracted base zip: {len(infos)} entries", flush=True)

    app = stage / APP_NAME
    contents = app / "Contents"
    if not app.is_dir():
        raise SystemExit("app bundle missing in base zip")

    # --- managed payload -------------------------------------------------
    dotnet = contents / "Resources" / "dotnet"
    if not dotnet.is_dir():
        raise SystemExit("bundle dotnet directory missing")
    for existing in sorted(dotnet.rglob("*"), reverse=True):
        if existing.is_file() or existing.is_symlink():
            existing.unlink()
        else:
            existing.rmdir()
    publish = args.publish_dir.resolve()
    apphost_name = plistlib.loads((contents / "Info.plist").read_bytes())["CFBundleExecutable"]
    apphost_source = publish / apphost_name
    if not apphost_source.is_file():
        raise SystemExit(f"publish output missing apphost: {apphost_name}")
    copied = 0
    for item in sorted(publish.rglob("*")):
        relative = item.relative_to(publish)
        if relative.as_posix() == apphost_name:
            continue
        if item.is_dir():
            (dotnet / relative).mkdir(parents=True, exist_ok=True)
            continue
        if item.suffix.lower() == ".pdb":
            continue
        destination = dotnet / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(item, destination)
        copied += 1
    shutil.copyfile(apphost_source, contents / "MacOS" / apphost_name)
    print(f"managed payload replaced: {copied} files + apphost", flush=True)

    # --- embedded darwin runtime ----------------------------------------
    runtime_in_bundle = contents / "Resources" / "bin" / "ultra-client"
    if not runtime_in_bundle.is_file():
        raise SystemExit("embedded ultra-client missing")
    runtime_bytes = args.runtime.read_bytes()
    runtime_sha = sha256_bytes(runtime_bytes)
    runtime_in_bundle.write_bytes(runtime_bytes)
    print(f"runtime replaced: sha256={runtime_sha} size={len(runtime_bytes)}", flush=True)

    # --- Info.plist version ---------------------------------------------
    plist_path = contents / "Info.plist"
    plist = plistlib.loads(plist_path.read_bytes())
    plist["CFBundleShortVersionString"] = args.version
    plist["CFBundleVersion"] = args.version
    plist_path.write_bytes(plistlib.dumps(plist))
    print(f"Info.plist version set to {args.version}", flush=True)

    # --- modes -----------------------------------------------------------
    for path in sorted(app.rglob("*")):
        if path.is_dir():
            path.chmod(0o755)
        else:
            path.chmod(0o644)
    (contents / "MacOS" / apphost_name).chmod(0o755)
    for helper in HELPERS:
        candidate = contents / "Resources" / "bin" / helper
        if candidate.is_file():
            candidate.chmod(0o755)

    # --- helper manifest + ad-hoc signature ------------------------------
    manifest_path = contents / "Resources" / "helper-manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    expected_arch = "arm64"
    if manifest.get("architecture") != expected_arch:
        raise SystemExit("helper manifest architecture mismatch")
    for helper in manifest["helpers"]:
        if helper.get("name") == "ultra-client":
            helper["sha256"] = runtime_sha
            helper["size_bytes"] = len(runtime_bytes)
    write_manifest(manifest_path, manifest)

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
        payload = write_manifest(manifest_path, manifest)
        if run == 0:
            previous_manifest = payload
        elif payload != previous_manifest:
            raise SystemExit(f"non-deterministic helper signatures (run {run})")
        print(f"signed pass {run}: rcodesign ok", flush=True)

    # --- audit -----------------------------------------------------------
    signed_runtime_sha = sha256(runtime_in_bundle)
    if signed_runtime_sha != runtime_sha:
        raise SystemExit(
            "signed bundle runtime differs from published runtime artifact: "
            f"{signed_runtime_sha} != {runtime_sha}")
    seal_path = contents / "_CodeSignature" / "CodeResources"
    seal = plistlib.loads(seal_path.read_bytes())
    sealed = seal.get("files2", {}).get("Resources/bin/ultra-client")
    if not sealed or sealed.get("hash2") != bytes.fromhex(signed_runtime_sha):
        raise SystemExit("seal does not cover bin/ultra-client with the new hash")
    apphost = contents / "MacOS" / apphost_name
    header = apphost.read_bytes()[:8]
    if len(header) != 8 or struct.unpack("<II", header) != (0xFEEDFACF, CPU[args.arch]):
        raise SystemExit("apphost architecture mismatch")
    if [p.name for p in (contents / "MacOS").iterdir() if p.name != apphost_name]:
        raise SystemExit("MacOS directory must contain only the apphost")
    helpers = manifest["helpers"]
    if len(helpers) != len(HELPERS) or {h["name"] for h in helpers} != set(HELPERS):
        raise SystemExit("helper manifest helper set mismatch")
    for helper in helpers:
        path = contents / "Resources" / helper["path"]
        if sha256(path) != helper["sha256"] or path.stat().st_size != helper["size_bytes"]:
            raise SystemExit(f"helper manifest mismatch after signing: {helper['name']}")
        if path.read_bytes()[:4] != b"\xcf\xfa\xed\xfe":
            raise SystemExit(f"helper lost Mach-O magic: {helper['name']}")
    print(f"helper manifest self-consistent ({len(helpers)} helpers)", flush=True)

    # --- rebuild zip with the original entry order and metadata ----------
    modes = {info.filename: info for info in infos}
    names = [info.filename for info in infos]
    actual = {APP_NAME + "/"}
    for path in app.rglob("*"):
        relative = path.relative_to(stage).as_posix()
        actual.add(relative + "/" if path.is_dir() else relative)
    missing = sorted(actual - set(names))
    if missing:
        raise SystemExit(f"bundle files missing from base zip layout: {missing[:5]}")
    stale = sorted(name for name in names if name not in actual)
    if stale:
        raise SystemExit(f"base zip entries no longer in bundle: {stale[:5]}")

    args.output_zip.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(args.output_zip, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as out:
        for name in names:
            original = modes[name]
            info = zipfile.ZipInfo(name, original.date_time)
            info.create_system = original.create_system
            info.external_attr = original.external_attr
            info.compress_type = zipfile.ZIP_STORED if original.is_dir() else zipfile.ZIP_DEFLATED
            data = b"" if original.is_dir() else (stage / name).read_bytes()
            out.writestr(info, data)
    with zipfile.ZipFile(args.output_zip) as out:
        assert out.testzip() is None, "zip integrity check failed"
        assert len(out.infolist()) == len(infos), "zip entry count changed"
        embedded = out.read(f"{APP_NAME}/Contents/Resources/bin/ultra-client")
        assert sha256_bytes(embedded) == runtime_sha, "embedded runtime hash mismatch"
        assert not any(i.filename.startswith("__MACOSX/") for i in out.infolist()), "__MACOSX leaked"
    print(f"zip rebuilt with {len(names)} entries", flush=True)

    report = {
        "architecture": args.arch,
        "bundle_version": plist["CFBundleShortVersionString"],
        "managed_files": copied,
        "runtime_sha256": runtime_sha,
        "runtime_size_bytes": len(runtime_bytes),
        "zip_entries": len(names),
        "zip_sha256": sha256(args.output_zip),
        "zip_size_bytes": args.output_zip.stat().st_size,
        "helpers": {
            h["name"]: {"sha256": h["sha256"], "size_bytes": h["size_bytes"], "path": h["path"]}
            for h in helpers
        },
        "signature": "ad-hoc (rcodesign 0.29.0), not Developer ID or notarized",
    }
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
