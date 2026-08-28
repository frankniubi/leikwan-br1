#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
build_script="$repo_root/scripts/build-macos-client.sh"
package_script="$repo_root/scripts/package-macos-client.sh"

fail() {
  printf 'test-macos-client-packaging: %s\n' "$*" >&2
  exit 1
}

expect_failure() {
  local label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    fail "$label unexpectedly passed"
  fi
}

write_macho() {
  local path="$1"
  local architecture="$2"
  python3 - "$path" "$architecture" <<'PY'
import pathlib
import struct
import sys

path = pathlib.Path(sys.argv[1])
cpu = 0x0100000C if sys.argv[2] == "arm64" else 0x01000007
path.parent.mkdir(parents=True, exist_ok=True)
path.write_bytes(b"\xcf\xfa\xed\xfe" + struct.pack("<I", cpu) + bytes(24))
PY
  chmod 0755 "$path"
}

make_fixture() {
  local root="$1"
  local architecture="$2"
  local publish="$root/publish"
  local helpers="$root/helpers"
  mkdir -p "$publish" "$helpers"
  write_macho "$publish/LeikwanBridge.Mac" "$architecture"
  printf '{}' >"$publish/LeikwanBridge.Mac.runtimeconfig.json"
  printf 'managed' >"$publish/LeikwanBridge.Mac.dll"
  for helper in sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client; do
    write_macho "$helpers/$helper" "$architecture"
  done
  (
    cd "$helpers"
    shasum -a 256 sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client >"$root/helper-hashes.txt"
  )
}

run_dry() {
  local fixture="$1"
  local fixture_rid="${2:-osx-arm64}"
  "$package_script" \
    --dry-run \
    --rid "$fixture_rid" \
    --version 2.0.0 \
    --publish-dir "$fixture/publish" \
    --helper-dir "$fixture/helpers" \
    --helper-hashes "$fixture/helper-hashes.txt" \
    --output-dir "$fixture/output"
}

[[ -f "$build_script" ]] || fail "build script missing"
[[ -f "$package_script" ]] || fail "package script missing"
bash -n "$build_script"
bash -n "$package_script"
build_plan="$(bash "$build_script" --rid osx-arm64 --dry-run)"
printf '%s\n' "$build_plan" | grep -Fqx \
  'wstunnel_commit=9d0ec3f27556e3132536401d1b2e4e1aa30c277c' \
  || fail "wstunnel commit pin is not the peeled v10.5.2 commit"
printf '%s\n' "$build_plan" | grep -Fqx \
  'dotnet restore LeikwanBridge.Mac --locked-mode' \
  || fail "locked restore must use the project dual-RID contract"
printf '%s\n' "$build_plan" | grep -Fqx \
  'uot_binary=leikwan-uot-bond' \
  || fail "UOT helper must use the Cargo binary name"

temp_root="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-macos-package-test.XXXXXX")"
trap 'rm -rf "$temp_root"' EXIT

good="$temp_root/good"
make_fixture "$good" arm64
run_dry "$good" >/dev/null

good_x64="$temp_root/good-x64"
make_fixture "$good_x64" x86_64
run_dry "$good_x64" osx-x64 >/dev/null

missing="$temp_root/missing"
make_fixture "$missing" arm64
rm "$missing/helpers/ultra-client"
expect_failure "missing helper" run_dry "$missing"

wrong_arch="$temp_root/wrong-arch"
make_fixture "$wrong_arch" arm64
write_macho "$wrong_arch/helpers/leikwan-bond" x86_64
(
  cd "$wrong_arch/helpers"
  shasum -a 256 sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client >"$wrong_arch/helper-hashes.txt"
)
expect_failure "wrong architecture" run_dry "$wrong_arch"

wrong_hash="$temp_root/wrong-hash"
make_fixture "$wrong_hash" arm64
printf 'tamper' >>"$wrong_hash/helpers/wstunnel"
expect_failure "helper hash mismatch" run_dry "$wrong_hash"

pdb="$temp_root/pdb"
make_fixture "$pdb" arm64
printf 'symbols' >"$pdb/publish/client.pdb"
expect_failure "PDB inclusion" run_dry "$pdb"

source="$temp_root/source"
make_fixture "$source" arm64
printf 'package main' >"$source/helpers/runtime.go"
expect_failure "source inclusion" run_dry "$source"

bundle="$temp_root/bundle"
make_fixture "$bundle" arm64
mkdir -p "$bundle/publish/Unexpected.app/Contents"
expect_failure "unexpected bundle path" run_dry "$bundle"

merge_root="$temp_root/merge-root"
mkdir -p "$merge_root/artifacts/macos"
printf 'dmg-bytes' >"$merge_root/artifacts/macos/leikwan-bridge-arm64.dmg"
printf 'zip-bytes' >"$merge_root/artifacts/macos/leikwan-bridge-update-arm64.zip"
printf '{"existing":{"version":"1.0.0"}}\n' >"$merge_root/artifacts/versions.json"
python3 - "$merge_root" <<'PY'
import hashlib
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
artifacts = {}
for key, filename, fmt in (
    ("macos_client_app_arm64", "leikwan-bridge-arm64.dmg", "dmg"),
    ("macos_client_update_arm64", "leikwan-bridge-update-arm64.zip", "zip"),
):
    path = root / "artifacts" / "macos" / filename
    data = path.read_bytes()
    artifacts[key] = {
        "version": "2.0.0",
        "path": "artifacts/macos/" + filename,
        "artifact_url": "/artifacts/macos/" + filename,
        "sha256": hashlib.sha256(data).hexdigest(),
        "size_bytes": len(data),
        "architecture": "arm64",
        "format": fmt,
        "unexpected": "must-not-be-merged",
    }
(root / "metadata.json").write_text(json.dumps({"artifacts": artifacts}), encoding="utf-8")
PY
"$package_script" \
  --repo-root "$merge_root" \
  --merge-versions "$merge_root/artifacts/versions.json" \
  --metadata "$merge_root/metadata.json" >/dev/null
python3 - "$merge_root/artifacts/versions.json" <<'PY'
import json
import pathlib
import sys

data = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
assert "existing" in data
assert data["macos_client_app_arm64"]["format"] == "dmg"
assert data["macos_client_update_arm64"]["format"] == "zip"
assert "unexpected" not in data["macos_client_app_arm64"]
PY
cp "$merge_root/artifacts/versions.json" "$merge_root/versions.before"
printf 'tamper' >>"$merge_root/artifacts/macos/leikwan-bridge-arm64.dmg"
expect_failure "metadata byte mismatch" "$package_script" \
  --repo-root "$merge_root" \
  --merge-versions "$merge_root/artifacts/versions.json" \
  --metadata "$merge_root/metadata.json"
cmp "$merge_root/versions.before" "$merge_root/artifacts/versions.json" >/dev/null \
  || fail "failed merge changed versions.json"

printf 'ok\n'
