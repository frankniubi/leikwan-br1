#!/bin/bash
set -uo pipefail
export LC_ALL=C
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
echo "--- stop any running client ---"
pkill -f '/Applications/LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null; sleep 3
pkill -f 'LeiKwan Bridge.app/Contents/Resources/bin/ultra-client' 2>/dev/null; sleep 1
echo "--- backup current install ---"
if [ -d "/Applications/LeiKwan Bridge.app" ]; then
  rm -rf "$HOME/lkb-backup-1031.app"
  ditto "/Applications/LeiKwan Bridge.app" "$HOME/lkb-backup-1031.app" 2>/dev/null
  echo -n "installed before: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "/Applications/LeiKwan Bridge.app/Contents/Info.plist"
fi
echo "--- mount 1.0.32 arm64 ---"
mnt=$(hdiutil attach -readonly -nobrowse -noverify "$HOME/lkb-smoke/arm64-1.0.32.dmg" | grep -o '/Volumes/.*' | head -1)
echo "mnt=$mnt"
sudo rm -rf "/Applications/LeiKwan Bridge.app"
sudo ditto "$mnt/LeiKwan Bridge.app" "/Applications/LeiKwan Bridge.app"
xattr -cr "/Applications/LeiKwan Bridge.app" 2>/dev/null || true
hdiutil detach "$mnt" >/dev/null 2>&1
echo -n "installed version: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "/Applications/LeiKwan Bridge.app/Contents/Info.plist"
echo -n "installed runtime: "; shasum -a 256 "/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client" | cut -c1-16
codesign --verify --deep --strict "/Applications/LeiKwan Bridge.app" >/dev/null 2>&1; echo "strict_verify=$?"
echo "--- config ---"
CFG="$HOME/Library/Application Support/LeiKwan/ClientAgent/config.json"
if [ -f "$CFG" ]; then
  python3 -c "import json;d=json.load(open('$CFG'));print('client_id',d.get('client_id') or d.get('ClientId'),'active',d.get('active_client_id') or d.get('ActiveClientId'),'token',('set' if (d.get('agent_token') or d.get('AgentToken')) else 'missing'),'panel',d.get('master_url') or d.get('MasterUrl'))"
else
  echo "config missing: $CFG"
fi
echo "--- launch ---"
launchctl asuser 501 open -a "/Applications/LeiKwan Bridge.app" 2>&1 | head -3
sleep 25
echo -n "app processes: "; pgrep -fc 'LeiKwan Bridge.app/Contents/MacOS' || echo 0
echo -n "ultra processes: "; pgrep -fc 'Resources/bin/ultra-client' || echo 0
echo "--- runtime binary hash ---"
pgrep -fl 'Resources/bin/ultra-client' | head -2
LOG="$HOME/Library/Logs/LeiKwan/ClientAgent"
ls -t "$LOG" 2>/dev/null | head -3
for f in $(ls -t "$LOG"/*.log 2>/dev/null | head -1); do
  echo "--- $f (tail) ---"; tail -12 "$f"
done
sleep 20
echo -n "ultra processes after 45s: "; pgrep -fc 'Resources/bin/ultra-client' || echo 0
for f in $(ls -t "$LOG"/*.log 2>/dev/null | head -1); do
  echo "--- lease/listener lines ---"; grep -E 'lease delivery|listener ready|ultra ' "$f" | tail -8
done
echo INSTALL-TEST-DONE
