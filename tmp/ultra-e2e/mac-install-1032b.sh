#!/bin/bash
export LC_ALL=C
set -u
echo "--- cleanup ---"
rm -rf "$HOME/lkb-smoke/b31-arm64" "$HOME/lkb-smoke/b31-x64" "$HOME/lkb-smoke/b32-arm64" "$HOME/lkb-smoke/b32-x64" \
       "$HOME/lkb-smoke/clean-arm64" "$HOME/lkb-smoke/clean-x64" "$HOME/lkb-smoke/lkb-install-test" \
       "$HOME/lkb-backup-1031.app" "$HOME/lkb-smoke/x64-1.0.30.dmg" "$HOME/lkb-smoke/x64-1.0.31.dmg" \
       "$HOME/lkb-smoke/x64-1.0.32.dmg" "$HOME/lkb-smoke/arm64-1.0.30.dmg" "$HOME/lkb-smoke/arm64-1.0.31.dmg" \
       "$HOME/lkb-smoke/arm64-native.dmg" "$HOME/lkb-smoke/x64-native.dmg" "$HOME/lkb-smoke/leikwan-bridge-x64.dmg"
df -h / | tail -1
echo "--- stop client ---"
pkill -f 'LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null
pkill -f 'Resources/bin/ultra-client' 2>/dev/null
sleep 4
echo "--- install 1.0.32 arm64 (user-writable /Applications) ---"
mnt=$(hdiutil attach -readonly -nobrowse -noverify "$HOME/lkb-smoke/arm64-1.0.32.dmg" | grep -o '/Volumes/.*' | head -1)
echo "mnt=[$mnt]"
rm -rf "/Applications/LeiKwan Bridge.app"
ditto "$mnt/LeiKwan Bridge.app" "/Applications/LeiKwan Bridge.app"
echo "ditto exit=$?"
xattr -cr "/Applications/LeiKwan Bridge.app" 2>/dev/null
hdiutil detach "$mnt" >/dev/null 2>&1
echo -n "installed version: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "/Applications/LeiKwan Bridge.app/Contents/Info.plist"
echo -n "installed runtime: "; shasum -a 256 "/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client" | cut -c1-16
codesign --verify --deep --strict "/Applications/LeiKwan Bridge.app" >/dev/null 2>&1; echo "strict_verify=$?"
echo "--- launch ---"
open -a "/Applications/LeiKwan Bridge.app" 2>&1 | head -3
sleep 30
echo -n "app processes: "; ps ax | grep -c '[L]eiKwan Bridge.app/Contents/MacOS'
echo -n "ultra processes: "; ps ax | grep -c '[R]esources/bin/ultra-client'
ps ax | grep '[R]esources/bin/ultra-client' | awk '{print $1}' | head -6
LOG="$HOME/Library/Logs/LeiKwan/ClientAgent"
LATEST=$(ls -t "$LOG"/*.log 2>/dev/null | head -1)
echo "log=$LATEST"
tail -6 "$LATEST" 2>/dev/null
sleep 30
echo -n "ultra processes after 60s: "; ps ax | grep -c '[R]esources/bin/ultra-client'
echo "--- lease lines ---"
grep -E 'lease delivery|listener ready' "$LATEST" 2>/dev/null | tail -6
echo INSTALL-1032-DONE
