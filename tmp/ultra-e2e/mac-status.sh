#!/bin/bash
export LC_ALL=C
APP="/Applications/LeiKwan Bridge.app"
echo -n "installed version: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist"
echo -n "installed runtime: "; shasum -a 256 "$APP/Contents/Resources/bin/ultra-client" | cut -c1-16
echo -n "ultra processes: "; ps aux | grep -c '[R]esources/bin/ultra-client'
echo -n "app processes: "; ps aux | grep -c '[L]eiKwan Bridge.app/Contents/MacOS'
ps aux | grep '[R]esources/bin/ultra-client' | awk '{print $2, $11, $12, $13}' | head -6
LOG="$HOME/Library/Logs/LeiKwan/ClientAgent"
LATEST=$(ls -t "$LOG"/*.log 2>/dev/null | head -1)
echo "log: $LATEST"
grep -c 'lease delivery result=true' "$LATEST" 2>/dev/null | sed 's/^/lease_delivery_true=/'
grep -c 'listener ready=true' "$LATEST" 2>/dev/null | sed 's/^/listener_ready_true=/'
grep 'ultra lease delivery' "$LATEST" 2>/dev/null | tail -3
echo "--- running ultra version stamp ---"
for p in $(pgrep -f 'Resources/bin/ultra-client' | head -2); do
  bin=$(ps -o comm= -p "$p")
  echo "pid=$p bin=$bin"
done
echo MAC-STATUS-DONE
