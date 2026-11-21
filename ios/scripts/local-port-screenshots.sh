#!/usr/bin/env bash
# Photograph the actual production LocalPortsView in a stopped Debug demo on one ordinary iPhone.
# Usage: local-port-screenshots.sh <path/to/Bridge.app> <output dir>
set -euo pipefail

app="$1"
out="$2"
mkdir -p "$out"
# macOS has no GNU timeout. Bound the command and its children, including simulator cleanup.
bounded() {
  python3 - "$@" <<'PY'
import datetime, os, signal, subprocess, sys
seconds = int(sys.argv[1])
command = sys.argv[2:]
print(datetime.datetime.now(datetime.timezone.utc).isoformat(), ' '.join(command),
      '(deadline %ss)' % seconds, file=sys.stderr, flush=True)
process = subprocess.Popen(command, start_new_session=True)
try:
    raise SystemExit(process.wait(timeout=seconds))
except subprocess.TimeoutExpired:
    print('TIMED OUT:', ' '.join(command), file=sys.stderr, flush=True)
    os.killpg(process.pid, signal.SIGKILL)
    process.wait(timeout=5)
    raise SystemExit(124)
PY
}

bundle_id="$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$app/Info.plist")"
bounded 15 xcrun simctl list runtimes -j > "$out/runtimes.json"
bounded 15 xcrun simctl list devices available -j > "$out/available-devices.json"
# Reuse a booted phone first, otherwise prefer an existing phone on the oldest installed iOS
# supported by the app (16+). Avoid Duo, tablet boot, and needless newest-runtime first boots.
python3 - "$out/runtimes.json" "$out/available-devices.json" > "$out/device.tsv" <<'PY'
import json, sys
runtimes = [r for r in json.load(open(sys.argv[1]))['runtimes']
            if r['name'].startswith('iOS ') and r.get('isAvailable')
            and int(r['version'].split('.')[0]) >= 16]
runtimes.sort(key=lambda r: tuple(map(int, r['version'].split('.'))))
phones = []
for index, runtime in enumerate(runtimes):
    for device in json.load(open(sys.argv[2]))['devices'].get(runtime['identifier'], []):
        if device.get('isAvailable') and device['name'].startswith('iPhone') and 'Duo' not in device['name']:
            rank = (device.get('state') != 'Booted', index, 'Max' not in device['name'], device['name'])
            phones.append((rank, device, runtime))
if phones:
    _, device, runtime = min(phones, key=lambda item: item[0])
    print('existing', device['udid'], runtime['identifier'], sep='\t')
else:
    for runtime in runtimes:
        devices = [t for t in runtime.get('supportedDeviceTypes', [])
                   if t['name'].startswith('iPhone') and 'Duo' not in t['name']]
        if devices:
            devices.sort(key=lambda t: ('Max' not in t['name'], t['name']))
            print('create', devices[0]['identifier'], runtime['identifier'], sep='\t')
            break
    else:
        raise SystemExit('No compatible ordinary iPhone simulator available')
PY

IFS=$'\t' read -r mode device runtime < "$out/device.tsv"
udid=""
created=NO
cleanup() {
  # Leave reused runner devices alone. Never let cleanup hold a captured PNG hostage.
  if [ "$created" = YES ] && [ -n "$udid" ]; then
    bounded 10 xcrun simctl shutdown "$udid" || true
    bounded 10 xcrun simctl delete "$udid" || true
  fi
}
trap cleanup EXIT
if [ "$mode" = create ]; then
  udid="$(bounded 20 xcrun simctl create bridge-local-ports-iphone "$device" "$runtime")"
  created=YES
else
  udid="$device"
fi
printf 'Capture iPhone %s on %s (%s)\n' "$udid" "$runtime" "$mode"
bounded 120 xcrun simctl bootstatus "$udid" -b
bounded 45 xcrun simctl install "$udid" "$app"
bounded 10 xcrun simctl ui "$udid" appearance light
bounded 10 xcrun simctl terminate "$udid" "$bundle_id" || true
bounded 30 xcrun simctl launch "$udid" "$bundle_id" \
  -privacy_disclosure_v1 YES -bridge_ci_demo YES -bridge_ci_local_ports YES \
  -bridge_ci_section dashboard -AppleLanguages '(zh-Hans)' -AppleLocale zh_CN
sleep 5
bounded 30 xcrun simctl io "$udid" screenshot "$out/local-ports-iphone-demo-stopped.png"
# Do not run `simctl spawn log show` here: collecting unified logs can stall after capture.
ls -l "$out/local-ports-iphone-demo-stopped.png"
