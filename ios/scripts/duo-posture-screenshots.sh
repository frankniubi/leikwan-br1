#!/usr/bin/env bash
# Boots an iPhone Duo simulator, installs a Debug build of the app and photographs three screens
# (the privacy cover, the sign-in screen, the demo dashboard) at four hinge postures on both
# displays. scripts/hinge posts the same event as Device Hub's hinge slider.
#
#   duo-posture-screenshots.sh <path/to/Bridge.app> <output dir>
#
# Output: <screen>-<degrees>-outer.png, <screen>-<degrees>-inner.png, hinge.log and bridge.log.
# Exits 0 without screenshots when this Xcode has no iPhone Duo simulator (Xcode 27.1 adds it).
set -euo pipefail

app="$1"
out="$2"
here="$(cd "$(dirname "$0")" && pwd)"
hinge="$here/hinge/hinge"
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

# Relaunches the app with the given launch arguments and photographs it at each posture:
# closed (0), half (90), book (127, the Duo's Book pose) and open (180).
shoot() {
  local screen="$1"
  shift
  xcrun simctl terminate "$udid" "$bundle_id" >/dev/null 2>&1 || true
  xcrun simctl launch "$udid" "$bundle_id" "$@" >/dev/null
  sleep 4
  for degrees in 0 90 127 180; do
    "$hinge" -d "$udid" "$degrees"
    sleep 3
    # Reading the angle back is best effort: it goes through devicectl, which may not cover simulators.
    reading="$("$hinge" -d "$udid" get 2>/dev/null || echo "not readable")"
    echo "$screen $degrees: set $degrees, read back $reading" | tee -a "$out/hinge.log"
    xcrun simctl io "$udid" screenshot --display=primary "$out/$screen-$degrees-outer.png"
    xcrun simctl io "$udid" screenshot --display=primary-1 "$out/$screen-$degrees-inner.png"
  done
}

# Order matters: the demo profile saved by the last screen would replace the sign-in screen.
shoot privacy -privacy_disclosure_v1 NO
shoot login -privacy_disclosure_v1 YES
shoot dashboard -privacy_disclosure_v1 YES -bridge_ci_demo YES

xcrun simctl spawn "$udid" log show --last 5m --style compact \
  --predicate 'process == "Bridge"' > "$out/bridge.log" 2>&1 || true
ls -l "$out"
