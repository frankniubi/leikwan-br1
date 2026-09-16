#!/bin/bash
export LC_ALL=C
echo "--- plist ---"
plutil -lint /tmp/lkb-headless.plist 2>&1 | head -1
echo -n "label: "; /usr/libexec/PlistBuddy -c 'Print :Label' /tmp/lkb-headless.plist 2>&1
/usr/libexec/PlistBuddy -c 'Print :ProgramArguments' /tmp/lkb-headless.plist 2>&1 | sed 's/^/    /' | head -16
echo -n "keepalive: "; /usr/libexec/PlistBuddy -c 'Print :KeepAlive:SuccessfulExit' /tmp/lkb-headless.plist 2>&1
echo -n "throttle: "; /usr/libexec/PlistBuddy -c 'Print :ThrottleInterval' /tmp/lkb-headless.plist 2>&1
echo "--- processes now ---"
ps ax | grep '[R]esources/bin/ultra-client' | awk '{print $1, $5}' | head -6
echo -n "count: "; ps ax | grep -c '[R]esources/bin/ultra-client'
echo "--- headless log ---"
ls -la /tmp/lkb-headless*.log 2>/dev/null
grep -E 'headless_(start|connected|stopped|signal)|lease delivery|listener ready' /tmp/lkb-headless*.log 2>/dev/null | tail -8
echo --- tail ---
tail -4 /tmp/lkb-headless*.log 2>/dev/null
echo MAC-FINAL-DONE
