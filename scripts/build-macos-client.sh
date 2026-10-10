#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
rid=""
version="1.0.0"
output_root=""
wstunnel_src="${WSTUNNEL_SRC:-}"
sing_box_src="${SING_BOX_SRC:-}"
garble_path="${GARBLE_PATH:-}"
dry_run=0
package_after_build=1
wstunnel_commit="9d0ec3f27556e3132536401d1b2e4e1aa30c277c"
wstunnel_repository="https://github.com/erebe/wstunnel.git"
sing_box_version="${SING_BOX_VERSION:-v1.13.12}"
sing_box_repository="https://github.com/SagerNet/sing-box.git"
garble_version="${GARBLE_VERSION:-v0.17.0}"
source_commit="${SOURCE_COMMIT:-}"
managed_load_receipt="${MANAGED_LOAD_RECEIPT:-}"

fail() {
  printf 'build-macos-client: %s\n' "$*" >&2
  exit 1
}

# Obfuscation guard for the Go ultra-client relay. A plain `go build -ldflags '-s -w'`
# strips the symbol table but NOT the pclntab, so tools/ultra-client function names
# (main.parseTarget, main.credentialChecksum, ...) and -literals-eligible strings
# (leikwan-ultra-policy-v2) still ship in cleartext. garble renames those and encrypts
# the literals; their absence proves the binary really went through garble instead of a
# silent fall-through to `go build`. runtimeBuildVersion is deliberately excluded: garble
# preserves the -X stamp so panel attestation (self_sha256 / fingerprint) keeps working.
assert_ultra_obfuscated() {
  local path="$1"
  local marker
  for marker in leikwan-ultra-policy-v2 parseTarget credentialChecksum runtimeAttestation copyInbound selfExecutableHash ParseNativeRoutePolicy; do
    if LC_ALL=C grep -a -q -F -e "$marker" "$path"; then
      fail "ultra-client still contains unobfuscated symbol '$marker'; garble did not run"
    fi
  done
}

usage() {
  cat <<'EOF'
Usage: scripts/build-macos-client.sh --rid <osx-arm64|osx-x64> [options]
  --version <version>       App version (default: 1.0.0)
  --output-root <path>      Intermediate output directory
  --wstunnel-src <path>     Upstream wstunnel checkout/cache
  --sing-box-src <path>     Upstream sing-box checkout/cache
  --managed-load-receipt <path>
                            Hash-bound probe receipt for cross-architecture packaging
  GARBLE_PATH=<path>        Pinned garble binary (default: PATH or temporary go install)
  --no-package              Build publish/helpers only
  --dry-run                 Print the pinned build plan without compiling
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rid) rid="${2:-}"; shift 2 ;;
    --version) version="${2:-}"; shift 2 ;;
    --output-root) output_root="${2:-}"; shift 2 ;;
    --wstunnel-src) wstunnel_src="${2:-}"; shift 2 ;;
    --sing-box-src) sing_box_src="${2:-}"; shift 2 ;;
    --managed-load-receipt) managed_load_receipt="${2:-}"; shift 2 ;;
    --no-package) package_after_build=0; shift ;;
    --dry-run) dry_run=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown argument: $1" ;;
  esac
done

case "$rid" in
  osx-arm64)
    rust_target="aarch64-apple-darwin"
    go_arch="arm64"
    architecture="arm64"
    ;;
  osx-x64)
    rust_target="x86_64-apple-darwin"
    go_arch="amd64"
    architecture="x86_64"
    ;;
  *) fail "--rid must be osx-arm64 or osx-x64" ;;
esac

[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.][0-9]+)?$ ]] || fail "version must be numeric"
output_root="${output_root:-$repo_root/.build/macos-client/$rid}"
wstunnel_src="${wstunnel_src:-$repo_root/.build/tool-sources/wstunnel-v10.5.2}"
sing_box_src="${sing_box_src:-$repo_root/.build/tool-sources/sing-box-$sing_box_version}"
mkdir -p "$(dirname "$output_root")" "$(dirname "$wstunnel_src")" "$(dirname "$sing_box_src")"
output_root="$(cd "$(dirname "$output_root")" && pwd)/$(basename "$output_root")"
wstunnel_src="$(cd "$(dirname "$wstunnel_src")" && pwd)/$(basename "$wstunnel_src")"
sing_box_src="$(cd "$(dirname "$sing_box_src")" && pwd)/$(basename "$sing_box_src")"
publish_dir="$output_root/publish"
helper_dir="$output_root/helpers"
helper_hashes="$output_root/helper-hashes.txt"

if [[ -z "$source_commit" ]]; then
  source_commit="$(git -C "$repo_root" rev-parse HEAD 2>/dev/null || printf unknown)"
fi

if [[ "$dry_run" -eq 1 ]]; then
  printf 'rid=%s\narchitecture=%s\nrust_target=%s\ngo_arch=%s\n' "$rid" "$architecture" "$rust_target" "$go_arch"
  printf 'wstunnel_commit=%s\n' "$wstunnel_commit"
  printf 'sing_box_version=%s\n' "$sing_box_version"
  printf 'dotnet restore LeikwanBridge.Mac --locked-mode\n'
  printf 'dotnet publish LeikwanBridge.Mac -r %s --self-contained true\n' "$rid"
  printf 'uot_binary=leikwan-uot-bond\n'
  printf 'cargo build --locked -p leikwan-bond -p leikwan-ingress -p leikwan-uot --target %s\n' "$rust_target"
  printf 'garble build GOOS=darwin GOARCH=%s -literals -trimpath ./tools/ultra-client\n' "$go_arch"
  printf 'ultra_obfuscator=garble-v0.17.0-literals\n'
  printf 'source_commit=%s\n' "$source_commit"
  printf 'go build GOOS=darwin GOARCH=%s -tags with_gvisor,with_quic,with_wireguard,with_utls ./cmd/sing-box\n' "$go_arch"
  printf 'package=%s\n' "$package_after_build"
  exit 0
fi

[[ "$(uname -s)" == "Darwin" ]] || fail "real builds require macOS"
for command in dotnet cargo rustup go git shasum strip base64; do
  command -v "$command" >/dev/null 2>&1 || fail "required command missing: $command"
done

if [[ -z "$garble_path" ]]; then
  garble_path="$(command -v garble || true)"
fi
if [[ -z "$garble_path" ]]; then
  garble_tool_dir="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-garble.XXXXXX")"
  trap 'rm -rf "$garble_tool_dir"' EXIT
  GOBIN="$garble_tool_dir" go install "mvdan.cc/garble@$garble_version"
  garble_path="$garble_tool_dir/garble"
fi
[[ -x "$garble_path" ]] || fail "garble binary missing"
garble_identity="$($garble_path version 2>/dev/null || true)"
printf '%s\n' "$garble_identity" | grep -Fq "$garble_version" \
  || fail "garble version mismatch: expected $garble_version"
garble_seed="$(printf 'leikwan-macos-%s-%s' "$version" "$rid" | base64 | tr -d '\n')"

python3 - "$repo_root" "$output_root" <<'PY'
import pathlib
import sys

root = pathlib.Path(sys.argv[1]).resolve()
output = pathlib.Path(sys.argv[2]).resolve()
if output == root or root not in output.parents:
    raise SystemExit("output-root must stay inside the repository")
if ".build" not in output.parts:
    raise SystemExit("output-root must stay under .build")
PY

rm -rf "$output_root"
mkdir -p "$publish_dir" "$helper_dir"

rustup target add "$rust_target"
cargo build \
  --manifest-path "$repo_root/wstunnel-main/Cargo.toml" \
  --locked \
  --release \
  --target "$rust_target" \
  -p leikwan-bond \
  -p leikwan-ingress \
  -p leikwan-uot

if [[ ! -d "$wstunnel_src/.git" ]]; then
  git clone --filter=blob:none "$wstunnel_repository" "$wstunnel_src"
fi
if ! git -C "$wstunnel_src" cat-file -e "$wstunnel_commit^{commit}" 2>/dev/null; then
  git -C "$wstunnel_src" fetch --depth=1 origin "$wstunnel_commit"
fi
git -C "$wstunnel_src" checkout --detach "$wstunnel_commit"
[[ "$(git -C "$wstunnel_src" rev-parse HEAD)" == "$wstunnel_commit" ]] || fail "wstunnel pin mismatch"
cargo build \
  --manifest-path "$wstunnel_src/Cargo.toml" \
  --locked \
  --release \
  --target "$rust_target"

(
  cd "$repo_root/tools/ultra-client"
  GOOS=darwin GOARCH="$go_arch" CGO_ENABLED=0 \
    "$garble_path" -literals -seed="$garble_seed" build \
      -mod=readonly -trimpath -buildvcs=false -ldflags='-s -w -buildid=' \
      -o "$helper_dir/ultra-client" .
)
assert_ultra_obfuscated "$helper_dir/ultra-client"

if [[ ! -d "$sing_box_src/.git" ]]; then
  git clone --filter=blob:none --branch "$sing_box_version" "$sing_box_repository" "$sing_box_src"
fi
if ! git -C "$sing_box_src" rev-parse --verify "refs/tags/$sing_box_version" >/dev/null 2>&1; then
  git -C "$sing_box_src" fetch --depth=1 origin "refs/tags/$sing_box_version"
fi
git -C "$sing_box_src" checkout --detach "$sing_box_version"
[[ "$(git -C "$sing_box_src" describe --tags --exact-match HEAD 2>/dev/null || true)" == "$sing_box_version" ]] \
  || fail "sing-box tag mismatch"
(
  cd "$sing_box_src"
  GOOS=darwin GOARCH="$go_arch" CGO_ENABLED=0 \
    go build -mod=readonly -trimpath \
      -tags 'with_gvisor,with_quic,with_wireguard,with_utls' \
      -ldflags='-s -w' -o "$helper_dir/sing-box" ./cmd/sing-box
)

dotnet restore \
  "$repo_root/LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj" \
  --locked-mode \
  -p:Version="$version"
dotnet publish \
  "$repo_root/LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj" \
  -c Release \
  -r "$rid" \
  --self-contained true \
  --no-restore \
  -p:Version="$version" \
  -p:DebugType=None \
  -p:DebugSymbols=false \
  -o "$publish_dir"

cp "$repo_root/wstunnel-main/target/$rust_target/release/leikwan-bond" "$helper_dir/leikwan-bond"
cp "$repo_root/wstunnel-main/target/$rust_target/release/leikwan-ingress" "$helper_dir/leikwan-ingress"
cp "$repo_root/wstunnel-main/target/$rust_target/release/leikwan-uot-bond" "$helper_dir/leikwan-uot-bond"
cp "$wstunnel_src/target/$rust_target/release/wstunnel" "$helper_dir/wstunnel"
for helper in sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client; do
  [[ -f "$helper_dir/$helper" ]] || fail "helper build output missing: $helper"
done
# Remove DWARF and local symbol names from native helpers after every build.
# The Go relay is already link-stripped by garble; this also covers Rust and
# sing-box Mach-O outputs without changing their exported entry points.
for helper in sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client; do
  strip -S -x "$helper_dir/$helper"
done
chmod 0755 "$helper_dir"/* "$publish_dir/LeikwanBridge.Mac"

(
  cd "$helper_dir"
  shasum -a 256 sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client >"$helper_hashes"
)

if [[ "$package_after_build" -eq 1 ]]; then
  package_args=(
    --rid "$rid" \
    --version "$version" \
    --publish-dir "$publish_dir" \
    --helper-dir "$helper_dir" \
    --helper-hashes "$helper_hashes" \
    --output-dir "$repo_root/artifacts/macos" \
    --source-commit "$source_commit"
  )
  if [[ -n "$managed_load_receipt" ]]; then
    package_args+=(--managed-load-receipt "$managed_load_receipt")
  fi
  bash "$repo_root/scripts/package-macos-client.sh" "${package_args[@]}"
fi

printf 'build complete: %s\n' "$output_root"
