#!/usr/bin/env bash
set -euo pipefail

# Run from any directory with a booted CI emulator. No enrollment or forwarding is started.
cd "$(dirname "${BASH_SOURCE[0]}")/.."
./gradlew :app:assembleDebug :app:assembleDebugAndroidTest \
  -Pqatest=true --no-daemon --max-workers=2

# Gradle's connected-test task uninstalls the target before screenshots can be pulled.
adb install -r app/build/outputs/apk/debug/app-debug.apk
adb install -r app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk
output="app/build/local-port-screenshots"
mkdir -p "$output"
# Prevent the emulator launcher ANR dialog from covering the actual app captures.
adb shell am force-stop com.google.android.apps.nexuslauncher
adb shell am instrument -w \
  -e class com.leikwan.bridge.ui.connections.LocalPortScreenshotsTest \
  com.leikwan.bridge.test/androidx.test.runner.AndroidJUnitRunner | tee "$output/instrumentation.txt"
grep -q 'OK (1 test)' "$output/instrumentation.txt"

for name in 01-wifi-selection.png 02-wifi-forwards.png 03-cellular-selection.png 04-cellular-forwards.png; do
  adb exec-out run-as com.leikwan.bridge cat "files/local-port-screenshots/$name" > "$output/$name"
  signature=$(od -An -tx1 -N8 "$output/$name" | tr -d ' \n')
  if [[ "$signature" != "89504e470d0a1a0a" ]]; then
    printf 'Invalid PNG capture: %s/%s\n' "$output" "$name" >&2
    exit 1
  fi
  printf 'Screenshot: %s/%s\n' "$output" "$name"
done
