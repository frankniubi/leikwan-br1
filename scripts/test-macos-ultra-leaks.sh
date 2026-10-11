#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture_mode=0
app_bundle=""
dmg_path=""
update_zip=""

fail() {
  printf 'test-macos-ultra-leaks: %s\n' "$*" >&2
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --fixture) fixture_mode=1; shift ;;
    --app-bundle) app_bundle="${2:-}"; shift 2 ;;
    --dmg) dmg_path="${2:-}"; shift 2 ;;
    --update-zip) update_zip="${2:-}"; shift 2 ;;
    *) fail "unknown argument: $1" ;;
  esac
done

seed="$(printf '%s' "$(date +%s)-$$-${RANDOM:-0}" | shasum -a 256 | awk '{print $1}')"
octet=$((16#${seed:0:2} % 200 + 20))
canary_ip="${CANARY_IP:-203.0.113.$octet}"
canary_target="${CANARY_TARGET:-198.51.100.$octet:443}"
canary_user="${CANARY_USER:-lk_canary_${seed:0:12}}"
canary_pass="${CANARY_PASS:-lk_secret_${seed:12:12}}"
unset CANARY_IP CANARY_TARGET CANARY_USER CANARY_PASS
canaries=("$canary_ip" "$canary_target" "$canary_user" "$canary_pass")

case "$canary_ip" in
  192.0.2.*|198.51.100.*|203.0.113.*) ;;
  *) fail "provider canary must use TEST-NET" ;;
esac
case "$canary_target" in
  192.0.2.*:*|198.51.100.*:*|203.0.113.*:*) ;;
  *) fail "target canary must use TEST-NET" ;;
esac
[[ "$canary_user" =~ ^lk_canary_[A-Za-z0-9_.-]+$ ]] || fail "user canary format invalid"
[[ "$canary_pass" =~ ^lk_secret_[A-Za-z0-9_.-]+$ ]] || fail "password canary format invalid"

file_contains_canary() {
  local file="$1"
  local value
  [[ -f "$file" ]] || return 1
  for value in "${canaries[@]}"; do
    if LC_ALL=C grep -a -F -q -- "$value" "$file" 2>/dev/null; then
      return 0
    fi
  done
  return 1
}

path_contains_canary() {
  local path="$1"
  local file
  [[ -e "$path" ]] || return 1
  if [[ -f "$path" ]]; then
    file_contains_canary "$path"
    return
  fi
  while IFS= read -r -d '' file; do
    if file_contains_canary "$file"; then
      return 0
    fi
  done < <(find -P "$path" -type f -print0 2>/dev/null)
  return 1
}

assert_clean_path() {
  local path="$1"
  local label="$2"
  path_contains_canary "$path" && fail "$label contains canary material"
  return 0
}

assert_clean_new_crashes() {
  local crash_root="$HOME/Library/Logs/DiagnosticReports"
  local crash_file
  [[ -d "$crash_root" ]] || return 0
  while IFS= read -r -d '' crash_file; do
    file_contains_canary "$crash_file" && fail "crash output contains canary material"
  done < <(find -P "$crash_root" -maxdepth 1 -type f -newer "$marker" -print0)
  return 0
}

if [[ "$fixture_mode" -eq 1 ]]; then
  fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-ultra-leak-scanner.XXXXXX")"
  trap 'rm -rf "$fixture_root"' EXIT
  printf 'safe fixture\n' >"$fixture_root/clean.txt"
  assert_clean_path "$fixture_root" "clean scanner fixture"
  printf '%s\n' "$canary_pass" >"$fixture_root/leak.txt"
  path_contains_canary "$fixture_root" || fail "scanner did not detect fixture canary"
  rm "$fixture_root/leak.txt"
  test_project="$repo_root/LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj"
  if command -v dotnet >/dev/null 2>&1; then
    dotnet test "$test_project" --filter FullyQualifiedName~MacUltraRuntimeHostTests -c Release >/dev/null
  elif command -v dotnet.exe >/dev/null 2>&1 && command -v wslpath >/dev/null 2>&1; then
    dotnet.exe test "$(wslpath -w "$test_project")" --filter FullyQualifiedName~MacUltraRuntimeHostTests -c Release >/dev/null
  else
    fail "dotnet runtime missing for fixture test"
  fi
  printf 'ok\n'
  exit 0
fi

[[ "$(uname -s)" == "Darwin" ]] || fail "native leak scan requires macOS"
[[ -d "$app_bundle" ]] || fail "app bundle missing"
[[ -f "$dmg_path" ]] || fail "DMG missing"
[[ -f "$update_zip" ]] || fail "update ZIP missing"
for command in hdiutil ditto python3 pgrep ps shasum; do
  command -v "$command" >/dev/null 2>&1 || fail "required command missing: $command"
done

app_executable="$app_bundle/Contents/MacOS/LeikwanBridge.Mac"
ultra_binary="$app_bundle/Contents/Resources/bin/ultra-client"
[[ -x "$app_executable" && -x "$ultra_binary" ]] || fail "app or Ultra executable missing"

state_root="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-ultra-leak.XXXXXX")"
release_file="$state_root/runtime/release"
input_fifo="$state_root/input.pipe"
control_log="$state_root/control.log"
marker="$state_root/started.marker"
zip_root="$state_root/update-zip"
mount_point=""
fixture_pid=""
ultra_pid=""
touch "$marker"
chmod 0700 "$state_root"
mkfifo "$input_fifo"
chmod 0600 "$input_fifo"

cleanup() {
  touch "$release_file" 2>/dev/null || true
  if [[ -n "$fixture_pid" ]] && kill -0 "$fixture_pid" 2>/dev/null; then
    kill "$fixture_pid" 2>/dev/null || true
    wait "$fixture_pid" 2>/dev/null || true
  fi
  if [[ -n "$mount_point" ]]; then
    hdiutil detach "$mount_point" -force >/dev/null 2>&1 || true
  fi
  case "$(basename "$state_root")" in
    leikwan-ultra-leak.*) rm -rf "$state_root" ;;
  esac
}
trap cleanup EXIT INT TERM

LEIKWAN_TEST_ULTRA_CANARY=1 "$app_executable" \
  --ultra-leak-fixture \
  --ultra-bin "$ultra_binary" \
  --state-root "$state_root/runtime" \
  --release-file "$release_file" \
  <"$input_fifo" >"$control_log" 2>&1 &
fixture_pid=$!

printf '{"Target":"%s","ProviderIp":"%s","User":"%s","Password":"%s"}' \
  "$canary_target" "$canary_ip" "$canary_user" "$canary_pass" >"$input_fifo"
rm "$input_fifo"

ready=0
for _ in $(seq 1 400); do
  if grep -q '^READY$' "$control_log" 2>/dev/null; then
    ready=1
    break
  fi
  kill -0 "$fixture_pid" 2>/dev/null || break
  sleep 0.05
done
[[ "$ready" -eq 1 ]] || fail "Ultra canary fixture did not become ready"

for _ in $(seq 1 100); do
  ultra_pid="$(pgrep -P "$fixture_pid" -f '/ultra-client' | head -n 1 || true)"
  [[ -n "$ultra_pid" ]] && break
  sleep 0.05
done
[[ -n "$ultra_pid" ]] || fail "Ultra child process not found"

process_snapshot="$(ps eww -p "$fixture_pid" -o command=; ps eww -p "$ultra_pid" -o command=)"
for value in "${canaries[@]}"; do
  printf '%s' "$process_snapshot" | LC_ALL=C grep -F -q -- "$value" \
    && fail "live argv/environment contains canary material"
done

assert_clean_path "$state_root" "runtime state"
assert_clean_path "$HOME/Library/Logs/LeiKwan/ClientAgent" "client logs"
assert_clean_path "$HOME/Library/Application Support/LeiKwan/ClientAgent/updates" "update cache"
assert_clean_path "$app_bundle" "app bundle"

mkdir -p "$zip_root"
ditto -x -k "$update_zip" "$zip_root"
assert_clean_path "$zip_root" "update ZIP"

attach_plist="$state_root/dmg-attach.plist"
hdiutil attach -readonly -nobrowse -plist "$dmg_path" >"$attach_plist"
mount_point="$(python3 - "$attach_plist" <<'PY'
import plistlib
import sys

with open(sys.argv[1], "rb") as handle:
    payload = plistlib.load(handle)
for entity in payload.get("system-entities", []):
    mount = entity.get("mount-point")
    if mount:
        print(mount)
        break
PY
)"
[[ -n "$mount_point" && -d "$mount_point" ]] || fail "DMG mount point missing"
assert_clean_path "$mount_point" "mounted DMG"

assert_clean_new_crashes

touch "$release_file"
if ! wait "$fixture_pid"; then
  fixture_pid=""
  # The control log sits in the state root the scans above found clean.
  grep -E '^(READY|DONE|FAILED)' "$control_log" >&2 || true
  fail "Ultra canary fixture exited with failure"
fi
fixture_pid=""
kill -0 "$ultra_pid" 2>/dev/null && fail "Ultra child remained alive after stop"
assert_clean_path "$state_root" "post-stop state"
assert_clean_new_crashes

printf 'ok\n'
