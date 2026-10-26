#!/usr/bin/env bash
# Boots an iPhone Duo simulator, installs a simulator build of the app, launches it and saves
# a screenshot of each display plus the app's recent log. Exits 0 without screenshots when
# this Xcode has no iPhone Duo simulator (Xcode 27.1 adds it).
#
#   duo-simulator-screenshots.sh <path/to/Bridge.app> <output dir>
set -euo pipefail

app="$1"
out="$2"
mkdir -p "$out"

device_type="$(xcrun simctl list devicetypes -j | python3 -c '
import json, sys
for t in json.load(sys.stdin)["devicetypes"]:
    if "iPhone Duo" in t["name"]:
        print(t["identifier"])
        break
')"
if [ -z "$device_type" ]; then
  echo "no iPhone Duo simulator in this Xcode; skipping"
  exit 0
fi

runtime="$(xcrun simctl list runtimes -j | python3 -c '
import json, sys
runtimes = [r for r in json.load(sys.stdin)["runtimes"] if r["name"].startswith("iOS ") and r.get("isAvailable")]
runtimes.sort(key=lambda r: [int(part) for part in r["version"].split(".")])
print(runtimes[-1]["identifier"] if runtimes else "")
')"
echo "device type $device_type, runtime $runtime"

udid="$(xcrun simctl create bridge-duo "$device_type" "$runtime")"
trap 'xcrun simctl shutdown "$udid" >/dev/null 2>&1 || true; xcrun simctl delete "$udid" >/dev/null 2>&1 || true' EXIT

xcrun simctl bootstatus "$udid" -b
xcrun simctl install "$udid" "$app"
bundle_id="$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$app/Info.plist")"
xcrun simctl launch "$udid" "$bundle_id"
sleep 10

xcrun simctl io "$udid" screenshot "$out/duo.png"
for display in internal external; do
  xcrun simctl io "$udid" screenshot --display="$display" "$out/duo-$display.png" || true
done
xcrun simctl spawn "$udid" log show --last 2m --style compact \
  --predicate 'process == "Bridge"' > "$out/bridge.log" 2>&1 || true
ls -l "$out"
