#!/usr/bin/env bash
set -euo pipefail

# Run from any directory with a booted CI emulator. No enrollment or forwarding is started.
cd "$(dirname "${BASH_SOURCE[0]}")/.."
./gradlew :app:connectedDebugAndroidTest \
  -Pqatest=true \
  -Pandroid.testInstrumentationRunnerArguments.class=com.leikwan.bridge.ui.connections.LocalPortScreenshotsTest \
  --no-daemon --max-workers=2

output="app/build/local-port-screenshots"
mkdir -p "$output"
for name in 01-wifi-selection.png 02-wifi-forwards.png 03-cellular-selection.png 04-cellular-forwards.png; do
  adb exec-out run-as com.leikwan.bridge cat "files/local-port-screenshots/$name" > "$output/$name"
  test -s "$output/$name"
  printf 'Screenshot: %s/%s\n' "$output" "$name"
done
