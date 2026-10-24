#!/usr/bin/env bash
# Developer ID sign, notarize, and staple a staged LeiKwan Bridge.app, then build,
# sign, notarize, and staple the release DMG from it.
#
# Called by scripts/package-macos-client.sh after prepare-macos-bundle.py has produced
# the ad-hoc sealed bundle, and before any DMG or update ZIP is created: the archives
# must contain the stapled, Developer ID signed app, and the helper manifest hashes
# must be rewritten before the outer bundle seal is made.
#
# Secrets are read from the environment only, never from arguments:
#   MACOS_DEVID_P12_BASE64     base64 of a "Developer ID Application" .p12
#   MACOS_DEVID_P12_PASSWORD   password of that .p12
#   ASC_KEY_P8                 App Store Connect API key (.p8 contents)
#   ASC_KEY_ID                 App Store Connect key id
#   ASC_ISSUER_ID              App Store Connect issuer id
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
app=""
dmg=""
report=""
entitlements="$repo_root/scripts/macos/client-entitlements.plist"
notary_timeout="60m"

fail() {
  printf 'sign-and-notarize-macos: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'EOF'
Usage: scripts/sign-and-notarize-macos.sh --app <LeiKwan Bridge.app> [options]
  --dmg <path>          Also build, sign, notarize, and staple this DMG from the app
  --report <path>       Write signed helper SHA-256 lines (for attestation records)
  --entitlements <path> Main-executable entitlements (default: scripts/macos/client-entitlements.plist)
Environment: MACOS_DEVID_P12_BASE64, MACOS_DEVID_P12_PASSWORD, ASC_KEY_P8, ASC_KEY_ID, ASC_ISSUER_ID
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app) app="${2:-}"; shift 2 ;;
    --dmg) dmg="${2:-}"; shift 2 ;;
    --report) report="${2:-}"; shift 2 ;;
    --entitlements) entitlements="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown argument: $1" ;;
  esac
done

[[ "$(uname -s)" == "Darwin" ]] || fail "Developer ID signing requires macOS"
for command in codesign security xcrun hdiutil ditto plutil python3 openssl spctl; do
  command -v "$command" >/dev/null 2>&1 || fail "required command missing: $command"
done
app="${app%/}"
[[ -d "$app" && "$app" == *.app ]] || fail "--app must be an existing .app bundle"
[[ -f "$entitlements" ]] || fail "entitlements file missing: $entitlements"
for variable in MACOS_DEVID_P12_BASE64 MACOS_DEVID_P12_PASSWORD ASC_KEY_P8 ASC_KEY_ID ASC_ISSUER_ID; do
  [[ -n "${!variable:-}" ]] || fail "required environment variable is empty: $variable"
done
[[ "$ASC_KEY_ID" =~ ^[A-Za-z0-9]+$ ]] || fail "ASC_KEY_ID has unexpected characters"

work_dir="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-devid.XXXXXX")"
keychain="$work_dir/devid.keychain-db"
keychain_password="$(openssl rand -hex 24)"
original_keychains=()
keychain_list_changed=0

cleanup() {
  local status=$?
  set +e
  if [[ "$keychain_list_changed" -eq 1 && "${#original_keychains[@]}" -gt 0 ]]; then
    security list-keychains -d user -s "${original_keychains[@]}" >/dev/null 2>&1
  fi
  if [[ -f "$keychain" ]]; then
    security delete-keychain "$keychain" >/dev/null 2>&1
  fi
  rm -rf "$work_dir"
  exit "$status"
}
trap cleanup EXIT

# Build the notary and disk-image inputs in the work dir and never in the caller's tree.
p12="$work_dir/devid.p12"
asc_key="$work_dir/AuthKey_${ASC_KEY_ID}.p8"
printf '%s' "$MACOS_DEVID_P12_BASE64" | openssl base64 -d -A -out "$p12" \
  || fail "MACOS_DEVID_P12_BASE64 is not valid base64"
printf '%s' "$ASC_KEY_P8" >"$asc_key"
chmod 0600 "$p12" "$asc_key"

# Temporary keychain, mirroring the iOS workflow. The existing search list is kept
# and restored on exit so the runner's login keychain is left untouched.
while IFS= read -r line; do
  line="${line#"${line%%[![:space:]]*}"}"
  line="${line%\"}"
  line="${line#\"}"
  [[ -n "$line" ]] && original_keychains+=("$line")
done < <(security list-keychains -d user)
security create-keychain -p "$keychain_password" "$keychain"
security set-keychain-settings -lut 3600 "$keychain"
security unlock-keychain -p "$keychain_password" "$keychain"
security import "$p12" -k "$keychain" -P "$MACOS_DEVID_P12_PASSWORD" -T /usr/bin/codesign >/dev/null \
  || fail "could not import the Developer ID .p12 (check MACOS_DEVID_P12_PASSWORD)"
security set-key-partition-list -S apple-tool:,apple:,codesign: -s -k "$keychain_password" "$keychain" >/dev/null
security list-keychains -d user -s "$keychain" ${original_keychains[@]+"${original_keychains[@]}"}
keychain_list_changed=1

identity="$(security find-identity -v -p codesigning "$keychain" \
  | awk -F'"' '/Developer ID Application:/ { print $1; exit }' | awk '{ print $2 }')"
[[ "$identity" =~ ^[0-9A-F]{40}$ ]] || fail "no Developer ID Application identity in the .p12"

# Mach-O detection by magic bytes (thin and fat, both endiannesses).
is_macho() {
  local magic
  magic="$(head -c 4 "$1" | od -An -tx1 | tr -d ' \n')"
  case "$magic" in
    cffaedfe|cefaedfe|feedfacf|feedface|cafebabe|cafebabf) return 0 ;;
    *) return 1 ;;
  esac
}

main_name="$(plutil -extract CFBundleExecutable raw "$app/Contents/Info.plist")"
[[ -n "$main_name" && "$main_name" != */* ]] || fail "invalid CFBundleExecutable"
main_executable="$app/Contents/MacOS/$main_name"
[[ -f "$main_executable" ]] || fail "main executable missing: $main_executable"

sign_code() {
  # Hardened runtime and a secure timestamp are both required for notarization.
  # Entitlements are applied only to the main executable (see the note in the
  # entitlements plist); nested code must not use --deep.
  local target="$1"
  shift
  codesign --force --timestamp --options runtime \
    --keychain "$keychain" --sign "$identity" "$@" "$target"
}

# 1. Nested Mach-O code, innermost first (files before enclosing frameworks).
nested_count=0
while IFS= read -r -d '' path; do
  [[ "$path" == "$main_executable" ]] && continue
  if is_macho "$path"; then
    sign_code "$path"
    nested_count=$((nested_count + 1))
  fi
done < <(find "$app" -type f -print0)
while IFS= read -r -d '' framework; do
  sign_code "$framework"
  nested_count=$((nested_count + 1))
done < <(find "$app" -depth -name '*.framework' -type d -print0)
printf 'signed nested code objects: %s\n' "$nested_count"

# 2. Signing changes the bytes of the helpers. LeiKwan Bridge checks each helper's
#    size and SHA-256 against Resources/helper-manifest.json at launch, so refresh it
#    before the outer bundle seal covers it.
python3 - "$app/Contents/Resources" <<'PY'
import hashlib
import json
import pathlib
import sys

resources = pathlib.Path(sys.argv[1]).resolve()
manifest_path = resources / "helper-manifest.json"
manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
helpers = manifest.get("helpers")
if not isinstance(helpers, list) or not helpers:
    raise SystemExit("helper manifest has no helpers")
for helper in helpers:
    path = (resources / helper["path"]).resolve()
    if resources not in path.parents or not path.is_file() or path.is_symlink():
        raise SystemExit(f"helper outside bundle or missing: {helper.get('name')}")
    data = path.read_bytes()
    helper["sha256"] = hashlib.sha256(data).hexdigest()
    helper["size_bytes"] = len(data)
manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
PY

# 3. Main executable and the bundle seal, with the hardened-runtime entitlements.
sign_code "$app" --entitlements "$entitlements"
codesign --verify --deep --strict --verbose=2 "$app"

# Every Mach-O in the sealed bundle must carry the Developer ID authority and the
# runtime flag; an ad-hoc leftover here would fail notarization with an opaque error.
while IFS= read -r -d '' path; do
  if is_macho "$path"; then
    details="$(codesign -dvv "$path" 2>&1)"
    grep -q 'Authority=Developer ID Application' <<<"$details" \
      || fail "not Developer ID signed: ${path#"$app"/}"
    grep -Eq 'flags=0x[0-9a-f]+\(.*runtime' <<<"$details" \
      || fail "hardened runtime missing: ${path#"$app"/}"
  fi
done < <(find "$app" -type f -print0)

# 4. Notarize the app. notarytool accepts a zip; the zip is temporary and is not
#    shipped, because stapling changes the bundle.
submit_and_wait() {
  local artifact="$1"
  local label="$2"
  local json="$work_dir/notary-$label.json"
  local status submission_id submit_status
  set +e
  xcrun notarytool submit "$artifact" \
    --key "$asc_key" --key-id "$ASC_KEY_ID" --issuer "$ASC_ISSUER_ID" \
    --wait --timeout "$notary_timeout" --output-format json >"$json" 2>"$work_dir/notary-$label.err"
  submit_status=$?
  set -e
  status="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("status",""))' "$json" 2>/dev/null || true)"
  submission_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("id",""))' "$json" 2>/dev/null || true)"
  if [[ "$submit_status" -ne 0 || "$status" != "Accepted" ]]; then
    if [[ -n "$submission_id" ]]; then
      xcrun notarytool log "$submission_id" \
        --key "$asc_key" --key-id "$ASC_KEY_ID" --issuer "$ASC_ISSUER_ID" 2>&1 | head -c 4000 >&2 || true
    fi
    cat "$work_dir/notary-$label.err" >&2 || true
    fail "notarization of $label not accepted (status: ${status:-none}, id: ${submission_id:-none})"
  fi
  printf 'notarization accepted: %s (%s)\n' "$label" "$submission_id"
}

app_zip="$work_dir/notary-app.zip"
ditto -c -k --sequesterRsrc --keepParent "$app" "$app_zip"
submit_and_wait "$app_zip" app
rm -f "$app_zip"

# 5. Staple the ticket to the app and check it, then the Gatekeeper decision.
xcrun stapler staple "$app" >/dev/null
xcrun stapler validate "$app" >/dev/null
spctl --assess --type execute --verbose=4 "$app"

# 6. Optional release DMG built from the stapled app, then signed and notarized itself.
if [[ -n "$dmg" ]]; then
  dmg_root="$work_dir/dmg-root"
  mkdir -p "$dmg_root"
  ditto "$app" "$dmg_root/LeiKwan Bridge.app"
  ln -s /Applications "$dmg_root/Applications"
  rm -f "$dmg"
  hdiutil create -volname 'LeiKwan Bridge' -srcfolder "$dmg_root" -ov -format UDZO "$dmg" >/dev/null
  codesign --force --timestamp --keychain "$keychain" --sign "$identity" "$dmg"
  codesign --verify --strict --verbose=2 "$dmg"
  submit_and_wait "$dmg" dmg
  xcrun stapler staple "$dmg" >/dev/null
  xcrun stapler validate "$dmg" >/dev/null
  spctl --assess --type open --context context:primary-signature --verbose=4 "$dmg"
fi

# 7. Record the signed helper hashes. The Ultra runtime's self-hash changes when it is
#    re-signed; the panel only accepts runtime hashes it has been told about, so the
#    release must register this value before clients are pointed at the build.
if [[ -n "$report" ]]; then
  : >"$report"
  while IFS= read -r -d '' path; do
    if is_macho "$path"; then
      printf '%s  %s\n' "$(shasum -a 256 "$path" | awk '{print tolower($1)}')" "${path#"$app"/}" >>"$report"
    fi
  done < <(find "$app/Contents/Resources/bin" -type f -print0)
fi

printf 'signed and notarized: %s\n' "$app"
[[ -z "$dmg" ]] || printf 'signed and notarized: %s\n' "$dmg"
