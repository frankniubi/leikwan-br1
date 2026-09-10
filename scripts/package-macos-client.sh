#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
rid=""
version=""
publish_dir=""
helper_dir=""
helper_hashes=""
output_dir=""
source_commit="unknown"
metadata_path=""
merge_versions=""
managed_load_receipt=""
dry_run=0
helpers="sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client"

fail() {
  printf 'package-macos-client: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'EOF'
Usage: scripts/package-macos-client.sh --rid <osx-arm64|osx-x64> --version <version> --publish-dir <path> --helper-dir <path> --helper-hashes <path> [options]
  --output-dir <path>       Artifact output directory
  --source-commit <sha>     Source revision for metadata
  --managed-load-receipt <path>
                            Hash-bound probe receipt for cross-architecture packaging
  --dry-run                 Validate inputs without creating an app/DMG/ZIP

Metadata merge mode:
  scripts/package-macos-client.sh --merge-versions <versions.json> --metadata <metadata.json>
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rid) rid="${2:-}"; shift 2 ;;
    --repo-root) repo_root="${2:-}"; shift 2 ;;
    --version) version="${2:-}"; shift 2 ;;
    --publish-dir) publish_dir="${2:-}"; shift 2 ;;
    --helper-dir) helper_dir="${2:-}"; shift 2 ;;
    --helper-hashes) helper_hashes="${2:-}"; shift 2 ;;
    --output-dir) output_dir="${2:-}"; shift 2 ;;
    --source-commit) source_commit="${2:-}"; shift 2 ;;
    --managed-load-receipt) managed_load_receipt="${2:-}"; shift 2 ;;
    --metadata) metadata_path="${2:-}"; shift 2 ;;
    --merge-versions) merge_versions="${2:-}"; shift 2 ;;
    --dry-run) dry_run=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown argument: $1" ;;
  esac
done

[[ -d "$repo_root" ]] || fail "repository root missing"
repo_root="$(cd "$repo_root" && pwd)"
output_dir="${output_dir:-$repo_root/artifacts/macos}"

sha256_file() {
  shasum -a 256 "$1" | awk '{print tolower($1)}'
}

file_size() {
  stat -f '%z' "$1" 2>/dev/null || stat -c '%s' "$1"
}

merge_metadata() {
  [[ -n "$metadata_path" ]] || fail "--metadata is required with --merge-versions"
  python3 - "$repo_root" "$merge_versions" "$metadata_path" <<'PY'
import hashlib
import json
import os
import pathlib
import re
import sys
import tempfile

root = pathlib.Path(sys.argv[1]).resolve()
versions_path = pathlib.Path(sys.argv[2]).resolve()
metadata_path = pathlib.Path(sys.argv[3]).resolve()
specifications = {
    "macos_client_app_arm64": ("artifacts/macos/leikwan-bridge-arm64.dmg", "/artifacts/macos/leikwan-bridge-arm64.dmg", "arm64", "dmg"),
    "macos_client_app_x64": ("artifacts/macos/leikwan-bridge-x64.dmg", "/artifacts/macos/leikwan-bridge-x64.dmg", "x86_64", "dmg"),
    "macos_client_update_arm64": ("artifacts/macos/leikwan-bridge-update-arm64.zip", "/artifacts/macos/leikwan-bridge-update-arm64.zip", "arm64", "zip"),
    "macos_client_update_x64": ("artifacts/macos/leikwan-bridge-update-x64.zip", "/artifacts/macos/leikwan-bridge-update-x64.zip", "x86_64", "zip"),
}

if versions_path != (root / "artifacts" / "versions.json").resolve():
    raise SystemExit("versions path must be artifacts/versions.json")

with versions_path.open("r", encoding="utf-8") as handle:
    versions = json.load(handle)
with metadata_path.open("r", encoding="utf-8") as handle:
    metadata = json.load(handle)
artifacts = metadata.get("artifacts")
if not isinstance(artifacts, dict) or not artifacts:
    raise SystemExit("metadata has no artifacts")

for key, entry in artifacts.items():
    if key not in specifications or not isinstance(entry, dict):
        raise SystemExit(f"unexpected metadata key: {key}")
    expected_path, expected_url, expected_architecture, expected_format = specifications[key]
    relative = pathlib.PurePosixPath(str(entry.get("path", "")))
    if relative.as_posix() != expected_path or relative.is_absolute() or ".." in relative.parts:
        raise SystemExit(f"invalid artifact path: {key}")
    artifact = (root / pathlib.Path(*relative.parts)).resolve()
    if root not in artifact.parents or not artifact.is_file():
        raise SystemExit(f"artifact missing or outside repository: {key}")
    digest = hashlib.sha256()
    with artifact.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    actual_hash = digest.hexdigest()
    actual_size = artifact.stat().st_size
    if actual_hash.lower() != str(entry.get("sha256", "")).lower():
        raise SystemExit(f"artifact hash mismatch: {key}")
    if actual_size != int(entry.get("size_bytes", 0)):
        raise SystemExit(f"artifact size mismatch: {key}")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?", str(entry.get("version", ""))):
        raise SystemExit(f"invalid artifact version: {key}")
    if str(entry.get("artifact_url", "")) != expected_url:
        raise SystemExit(f"invalid artifact URL: {key}")
    if str(entry.get("architecture", "")) != expected_architecture:
        raise SystemExit(f"invalid artifact architecture: {key}")
    if str(entry.get("format", "")) != expected_format:
        raise SystemExit(f"invalid artifact format: {key}")
    merged = {
        "version": str(entry["version"]),
        "path": expected_path,
        "artifact_url": expected_url,
        "sha256": actual_hash,
        "size_bytes": actual_size,
        "architecture": expected_architecture,
        "format": expected_format,
    }
    hardening = entry.get("hardening")
    if isinstance(hardening, dict):
        allowed_hardening = {
            name: str(hardening[name])
            for name in ("ultra_client", "native_helpers", "managed_symbols")
            if name in hardening and str(hardening[name]).strip()
        }
        if allowed_hardening:
            merged["hardening"] = allowed_hardening
    runtime_sha256 = entry.get("runtime_sha256")
    if not isinstance(runtime_sha256, dict) or not runtime_sha256:
        raise SystemExit(f"runtime hashes missing: {key}")
    normalized_runtime = {}
    for runtime_path, runtime_hash in runtime_sha256.items():
        relative_runtime = pathlib.PurePosixPath(str(runtime_path))
        if (relative_runtime.as_posix() != str(runtime_path)
                or not str(runtime_path).startswith("bin/")
                or relative_runtime.is_absolute()
                or ".." in relative_runtime.parts
                or not re.fullmatch(r"[0-9a-fA-F]{64}", str(runtime_hash))):
            raise SystemExit(f"invalid runtime hash: {key}:{runtime_path}")
        normalized_runtime[str(runtime_path)] = str(runtime_hash).lower()
    merged["runtime_sha256"] = normalized_runtime
    versions[key] = merged

versions_path.parent.mkdir(parents=True, exist_ok=True)
fd, temporary = tempfile.mkstemp(prefix=versions_path.name + ".", suffix=".tmp", dir=versions_path.parent)
try:
    os.chmod(temporary, versions_path.stat().st_mode & 0o777)
    with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(versions, handle, ensure_ascii=False, indent=1, sort_keys=True)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, versions_path)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
PY
}

if [[ -n "$merge_versions" ]]; then
  merge_metadata
  printf 'merged metadata: %s\n' "$metadata_path"
  exit 0
fi

case "$rid" in
  osx-arm64) architecture="arm64"; suffix="arm64" ;;
  osx-x64) architecture="x86_64"; suffix="x64" ;;
  *) fail "--rid must be osx-arm64 or osx-x64" ;;
esac
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.][0-9]+)?$ ]] || fail "version must be numeric"
[[ -d "$publish_dir" ]] || fail "publish directory missing"
[[ -d "$helper_dir" ]] || fail "helper directory missing"
[[ -f "$helper_hashes" ]] || fail "helper hash manifest missing"
[[ -f "$publish_dir/LeikwanBridge.Mac" ]] || fail "main executable missing"
for managed_file in \
  LeikwanBridge.Mac.dll \
  LeikwanBridge.Core.dll \
  LeikwanBridge.Mac.deps.json \
  LeikwanBridge.Mac.runtimeconfig.json; do
  [[ -f "$publish_dir/$managed_file" ]] || fail "managed publish file missing: $managed_file"
done
python3 - "$publish_dir" "$version" "$rid" <<'PY'
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
version, rid = sys.argv[2:]
deps = json.loads((root / 'LeikwanBridge.Mac.deps.json').read_text(encoding='utf-8-sig'))
target_name = deps['runtimeTarget']['name']
if not target_name.endswith('/' + rid):
    raise SystemExit('managed dependency manifest RID mismatch')
target = deps['targets'][target_name]
app = target.get('LeikwanBridge.Mac/' + version)
if app is None:
    raise SystemExit('managed dependency manifest version mismatch')
core_version = app['dependencies']['LeikwanBridge.Core']
core = target['LeikwanBridge.Core/' + core_version]
if 'LeikwanBridge.Mac.dll' not in app['runtime'] or 'LeikwanBridge.Core.dll' not in core['runtime']:
    raise SystemExit('managed dependency manifest assembly missing')
json.loads((root / 'LeikwanBridge.Mac.runtimeconfig.json').read_text(encoding='utf-8-sig'))
PY

forbidden_input="$(find "$publish_dir" "$helper_dir" -type f \( \
  -name '*.pdb' -o -name '*.cs' -o -name '*.fs' -o -name '*.vb' -o \
  -name '*.go' -o -name '*.rs' -o -name '*.map' -o -name '*.env' -o \
  -name '*.pem' -o -name '*.key' -o -name '*.p12' -o -iname '*credential*' -o -iname '*secret*' \
\) -print -quit)"
[[ -z "$forbidden_input" ]] || fail "debug/source/private material found in package inputs"
unexpected_app="$(find "$publish_dir" -type d -name '*.app' -print -quit)"
[[ -z "$unexpected_app" ]] || fail "publish input already contains an app bundle"

check_architecture() {
  local path="$1"
  local detected=""
  if command -v lipo >/dev/null 2>&1 && detected="$(lipo -archs "$path" 2>/dev/null)"; then
    [[ "$detected" == "$architecture" ]] || fail "wrong architecture for $(basename "$path"): $detected"
    return
  fi
  detected="$(file -b "$path" 2>/dev/null || true)"
  case "$architecture" in
    arm64) printf '%s' "$detected" | grep -Eqi 'Mach-O.*(arm64|aarch64)' || fail "wrong architecture for $(basename "$path"): $detected" ;;
    x86_64) printf '%s' "$detected" | grep -Eqi 'Mach-O.*(x86_64|x86-64)' || fail "wrong architecture for $(basename "$path"): $detected" ;;
  esac
}

check_architecture "$publish_dir/LeikwanBridge.Mac"
for helper in $helpers; do
  path="$helper_dir/$helper"
  [[ -f "$path" ]] || fail "required helper missing: $helper"
  expected="$(awk -v name="$helper" '{ sub(/\r$/, "") } $2 == name || $2 == "*" name { print tolower($1) }' "$helper_hashes")"
  [[ "$expected" =~ ^[a-f0-9]{64}$ ]] || fail "helper hash entry missing or duplicated: $helper"
  actual="$(sha256_file "$path")"
  [[ "$actual" == "$expected" ]] || fail "helper hash mismatch: $helper"
  check_architecture "$path"
done

extra_helper="$(find "$helper_dir" -mindepth 1 -maxdepth 1 -type f ! -name 'sing-box' ! -name 'wstunnel' ! -name 'leikwan-bond' ! -name 'leikwan-ingress' ! -name 'leikwan-uot-bond' ! -name 'ultra-client' -print -quit)"
[[ -z "$extra_helper" ]] || fail "unexpected helper input: $(basename "$extra_helper")"

if [[ "$dry_run" -eq 1 ]]; then
  printf 'dry-run ok: %s %s\n' "$rid" "$version"
  exit 0
fi

[[ "$(uname -s)" == "Darwin" ]] || fail "DMG packaging requires macOS"
for command in hdiutil ditto sips iconutil plutil python3 shasum file codesign; do
  command -v "$command" >/dev/null 2>&1 || fail "required command missing: $command"
done

template="$repo_root/LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Info.plist.template"
icon_png="$repo_root/LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Assets/app-icon.png"
[[ -f "$template" && -f "$icon_png" ]] || fail "bundle template or icon missing"
mkdir -p "$output_dir"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-macos-package.XXXXXX")"
mount_point=""
cleanup_package() {
  if [[ -n "$mount_point" ]] && ! hdiutil detach "$mount_point" >/dev/null 2>&1; then
    printf 'package-macos-client: retaining mounted verification directory: %s\n' "$work_dir" >&2
    return
  fi
  rm -rf "$work_dir"
}
trap cleanup_package EXIT
app="$work_dir/LeiKwan Bridge.app"
macos_dir="$app/Contents/MacOS"
resources_dir="$app/Contents/Resources"
bundle_helpers="$resources_dir/bin"
managed_dir="$resources_dir/dotnet"
mkdir -p "$macos_dir" "$bundle_helpers" "$managed_dir"
cp -R "$publish_dir"/. "$managed_dir"/
mv "$managed_dir/LeikwanBridge.Mac" "$macos_dir/LeikwanBridge.Mac"
for helper in $helpers; do
  cp "$helper_dir/$helper" "$bundle_helpers/$helper"
done
notice_path="$repo_root/docs/third-party/sing-box-notice.txt"
[[ -f "$notice_path" ]] || fail "sing-box third-party notice missing"
cp "$notice_path" "$resources_dir/THIRD-PARTY-SING-BOX.txt"

python3 - "$template" "$app/Contents/Info.plist" "$version" <<'PY'
import pathlib
import sys

template = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8")
version = sys.argv[3]
if template.count("@BUNDLE_VERSION@") != 2:
    raise SystemExit("Info.plist template placeholder mismatch")
pathlib.Path(sys.argv[2]).write_text(template.replace("@BUNDLE_VERSION@", version), encoding="utf-8")
PY

iconset="$work_dir/AppIcon.iconset"
mkdir -p "$iconset"
for size in 16 32 128 256 512; do
  sips -z "$size" "$size" "$icon_png" --out "$iconset/icon_${size}x${size}.png" >/dev/null
  double=$((size * 2))
  sips -z "$double" "$double" "$icon_png" --out "$iconset/icon_${size}x${size}@2x.png" >/dev/null
done
iconutil -c icns "$iconset" -o "$resources_dir/AppIcon.icns"

python3 - "$bundle_helpers" "$resources_dir/helper-manifest.json" "$architecture" $helpers <<'PY'
import hashlib
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
output = pathlib.Path(sys.argv[2])
architecture = sys.argv[3]
items = []
for name in sys.argv[4:]:
    path = root / name
    data = path.read_bytes()
    items.append({
        "name": name,
        "path": "bin/" + name,
        "sha256": hashlib.sha256(data).hexdigest(),
        "size_bytes": len(data),
    })
output.write_text(json.dumps({"architecture": architecture, "helpers": items}, indent=2) + "\n", encoding="utf-8")
PY

find "$app" -type d -exec chmod 0755 {} +
find "$app" -type f -exec chmod 0644 {} +
chmod 0755 "$macos_dir/LeikwanBridge.Mac"
for helper in $helpers; do chmod 0755 "$bundle_helpers/$helper"; done
plutil -lint "$app/Contents/Info.plist" >/dev/null
check_architecture "$macos_dir/LeikwanBridge.Mac"
for helper in $helpers; do check_architecture "$bundle_helpers/$helper"; done
forbidden_bundle="$(find "$app" -type f \( \
  -name '*.pdb' -o -name '*.cs' -o -name '*.go' -o -name '*.rs' -o -name '*.env' -o \
  -name '*.pem' -o -name '*.key' -o -name '*.p12' -o -iname '*credential*' -o -iname '*secret*' \
\) -print -quit)"
[[ -z "$forbidden_bundle" ]] || fail "forbidden material entered app bundle"
python3 "$repo_root/scripts/prepare-macos-bundle.py" "$app"

host_architecture="$(uname -m)"
if [[ "$host_architecture" == "$architecture" ]]; then
  load_probe_log="$work_dir/managed-load-probe.log"
  set +e
  LEIKWAN_TEST_ULTRA_CANARY= "$macos_dir/LeikwanBridge.Mac" --ultra-leak-fixture \
    </dev/null >"$load_probe_log" 2>&1
  load_probe_status=$?
  set -e
  if [[ "$load_probe_status" -ne 2 ]]; then
    cat "$load_probe_log" >&2 || true
    fail "managed bundle load probe failed with exit $load_probe_status"
  fi
else
  [[ -f "$managed_load_receipt" ]] \
    || fail "managed load receipt required for cross-architecture packaging"
  python3 "$repo_root/scripts/verify-macos-managed-load-receipt.py" \
    "$publish_dir" "$managed_load_receipt" --rid "$rid" --version "$version"
fi

dmg="$output_dir/leikwan-bridge-$suffix.dmg"
update_zip="$output_dir/leikwan-bridge-update-$suffix.zip"
metadata="$output_dir/leikwan-bridge-$suffix-metadata.json"
dmg_root="$work_dir/dmg-root"
mkdir -p "$dmg_root"
ditto "$app" "$dmg_root/LeiKwan Bridge.app"
ln -s /Applications "$dmg_root/Applications"
rm -f "$dmg" "$update_zip" "$metadata"
hdiutil create -volname 'LeiKwan Bridge' -srcfolder "$dmg_root" -ov -format UDZO "$dmg" >/dev/null
hdiutil verify "$dmg" >/dev/null
mount_point="$work_dir/verify-mount"
mkdir -p "$mount_point"
hdiutil attach -readonly -nobrowse -mountpoint "$mount_point" "$dmg" >/dev/null
python3 "$repo_root/scripts/verify-macos-dmg-layout.py" "$mount_point" \
  --version "$version" --architecture "$architecture"
codesign --verify --deep --strict --verbose=2 "$mount_point/LeiKwan Bridge.app"
hdiutil detach "$mount_point" >/dev/null
mount_point=""
ditto -c -k --sequesterRsrc --keepParent "$app" "$update_zip"

dmg_sha="$(sha256_file "$dmg")"
zip_sha="$(sha256_file "$update_zip")"
dmg_size="$(file_size "$dmg")"
zip_size="$(file_size "$update_zip")"
go_version="$(go version 2>/dev/null || printf unknown)"
rust_version="$(rustc --version 2>/dev/null || printf unknown)"
dotnet_version="$(dotnet --version 2>/dev/null || printf unknown)"

python3 - "$metadata" "$version" "$architecture" "$suffix" "$source_commit" \
  "$dmg_sha" "$dmg_size" "$zip_sha" "$zip_size" "$resources_dir/helper-manifest.json" \
  "$go_version" "$rust_version" "$dotnet_version" <<'PY'
import json
import pathlib
import sys

(
    output,
    version,
    architecture,
    suffix,
    source_commit,
    dmg_sha,
    dmg_size,
    zip_sha,
    zip_size,
    helper_manifest,
    go_version,
    rust_version,
    dotnet_version,
) = sys.argv[1:]
app_key = "macos_client_app_" + suffix
update_key = "macos_client_update_" + suffix
dmg_name = "leikwan-bridge-" + suffix + ".dmg"
zip_name = "leikwan-bridge-update-" + suffix + ".zip"
helpers = json.loads(pathlib.Path(helper_manifest).read_text(encoding="utf-8"))
runtime_sha256 = {
    str(helper["path"]): str(helper["sha256"]).lower()
    for helper in helpers.get("helpers", [])
    if isinstance(helper, dict) and helper.get("path") and helper.get("sha256")
}
if not runtime_sha256:
    raise SystemExit("helper manifest has no runtime hashes")
payload = {
    "version": version,
    "architecture": architecture,
    "source_commit": source_commit,
    "artifacts": {
        app_key: {
            "version": version,
            "path": "artifacts/macos/" + dmg_name,
            "artifact_url": "/artifacts/macos/" + dmg_name,
            "sha256": dmg_sha,
            "size_bytes": int(dmg_size),
            "architecture": architecture,
            "format": "dmg",
            "hardening": {
                "ultra_client": "garble-v0.17.0-literals-tiny",
                "native_helpers": "release-strip",
                "managed_symbols": "disabled",
            },
            "runtime_sha256": runtime_sha256,
        },
        update_key: {
            "version": version,
            "path": "artifacts/macos/" + zip_name,
            "artifact_url": "/artifacts/macos/" + zip_name,
            "sha256": zip_sha,
            "size_bytes": int(zip_size),
            "architecture": architecture,
            "format": "zip",
            "hardening": {
                "ultra_client": "garble-v0.17.0-literals-tiny",
                "native_helpers": "release-strip",
                "managed_symbols": "disabled",
            },
            "runtime_sha256": runtime_sha256,
        },
    },
    "helpers": helpers,
    "toolchain": {
        "go": go_version,
        "rust": rust_version,
        "dotnet": dotnet_version,
    },
}
pathlib.Path(output).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

printf 'created %s\ncreated %s\ncreated %s\n' "$dmg" "$update_zip" "$metadata"
