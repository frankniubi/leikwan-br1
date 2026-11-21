#!/usr/bin/env bash
# Capture the actual production LocalPortsView in a Debug simulator app, stopped with demo data.
# Usage: local-port-screenshots.sh <path/to/Bridge.app> <output dir>
# A large iPhone shows the ordinary phone layout; an iPad also exposes the lower forward list.
set -euo pipefail

app="$1"
out="$2"
mkdir -p "$out"
bundle_id="$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$app/Info.plist")"
# Choose a compatible phone/tablet pair from the newest installed runtime. No Duo dependency.
xcrun simctl list runtimes -j > "$out/runtimes.json"
python3 - "$out/runtimes.json" > "$out/devices.tsv" <<'PY'
import json, sys
runtimes = [r for r in json.load(open(sys.argv[1]))['runtimes']
            if r['name'].startswith('iOS ') and r.get('isAvailable')]
runtimes.sort(key=lambda r: tuple(map(int, r['version'].split('.'))), reverse=True)
for kind, prefix in [('iphone', 'iPhone'), ('ipad', 'iPad')]:
    for runtime in runtimes:
        devices = [t for t in runtime.get('supportedDeviceTypes', [])
                   if t['name'].startswith(prefix) and 'Duo' not in t['name']]
        if devices:
            devices.sort(key=lambda t: ('Max' in t['name'] or '13-inch' in t['name'],
                                        'Plus' in t['name'], 'Pro' in t['name'], t['name']), reverse=True)
            print(kind, devices[0]['identifier'], runtime['identifier'], sep='\t')
            break
    else:
        if kind == 'iphone':
            raise SystemExit('No compatible iPhone simulator runtime installed')
PY

udid=""
cleanup() {
  if [ -n "$udid" ]; then
    xcrun simctl shutdown "$udid" >/dev/null 2>&1 || true
    xcrun simctl delete "$udid" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT
while IFS=$'\t' read -r kind device_type runtime; do
  udid="$(xcrun simctl create "bridge-local-ports-$kind" "$device_type" "$runtime")"
  xcrun simctl bootstatus "$udid" -b
  xcrun simctl install "$udid" "$app"
  xcrun simctl ui "$udid" appearance light
  xcrun simctl launch "$udid" "$bundle_id" \
    -privacy_disclosure_v1 YES -bridge_ci_demo YES -bridge_ci_local_ports YES \
    -bridge_ci_section dashboard -AppleLanguages '(en)' -AppleLocale en_US
  sleep 5
  xcrun simctl io "$udid" screenshot "$out/local-ports-$kind-demo-stopped.png"
  xcrun simctl spawn "$udid" log show --last 1m --style compact \
    --predicate 'process == "Bridge"' > "$out/$kind-bridge.log" 2>&1 || true
  cleanup
  udid=""
done < "$out/devices.tsv"
ls -l "$out"
