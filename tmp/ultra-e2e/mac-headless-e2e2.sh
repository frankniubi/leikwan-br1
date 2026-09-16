#!/bin/bash
export LC_ALL=C
set -u
APP="/Applications/LeiKwan Bridge.app"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
echo -n "version: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist"
echo -n "runtime: "; shasum -a 256 "$APP/Contents/Resources/bin/ultra-client" | cut -c1-16
echo "--- plist validation (print mode, no root) ---"
sh "$HOME/lkb-headless-install.sh" --print-plist --panel https://v3.leikwanhost.com --client-id 3 --agent-token dummy > /tmp/lkb-headless.plist 2>/tmp/lkb-plist.err
echo "print-plist exit=$?"
head -2 /tmp/lkb-plist.err 2>/dev/null
plutil -lint /tmp/lkb-headless.plist 2>&1 | head -1
echo -n "label: "; /usr/libexec/PlistBuddy -c 'Print :Label' /tmp/lkb-headless.plist 2>&1
echo "arguments:"; /usr/libexec/PlistBuddy -c 'Print :ProgramArguments' /tmp/lkb-headless.plist 2>&1 | head -14
echo -n "keepalive: "; /usr/libexec/PlistBuddy -c 'Print :KeepAlive:SuccessfulExit' /tmp/lkb-headless.plist 2>&1
echo "--- headless run using the stored client identity ---"
pkill -f 'LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null
pkill -f 'Resources/bin/ultra-client' 2>/dev/null
sleep 4
rm -f /tmp/lkb-headless.log /tmp/lkb-headless-status.json /tmp/lkb-headless.out
"$EXE" --headless --panel https://v3.leikwanhost.com --client-id 3 \
  --status-file /tmp/lkb-headless-status.json --log-file /tmp/lkb-headless.log --print-status \
  > /tmp/lkb-headless.out 2>&1 &
PID=$!
sleep 80
echo "--- during run ---"
echo -n "ultra processes: "; ps ax | grep -c '[R]esources/bin/ultra-client'
echo -n "status: "; head -c 700 /tmp/lkb-headless-status.json 2>/dev/null; echo
echo -n "headless pid alive: "; ps -p $PID >/dev/null 2>&1 && echo yes || echo no
echo "--- SIGTERM (launchd stop path) ---"
kill -TERM $PID 2>/dev/null
sleep 18
echo -n "still running: "; ps -p $PID >/dev/null 2>&1 && echo yes || echo no
echo -n "ultra processes after stop: "; ps ax | grep -c '[R]esources/bin/ultra-client'
echo "--- log ---"
grep -E 'headless_(start|connected|stopped|signal|forward|connect_failed)|lease delivery|listener ready' /tmp/lkb-headless.log 2>/dev/null | tail -14
echo "--- log tail ---"
tail -5 /tmp/lkb-headless.log 2>/dev/null
echo "--- stdout ---"
tail -3 /tmp/lkb-headless.out
echo MAC-HEADLESS-E2E2-DONE
