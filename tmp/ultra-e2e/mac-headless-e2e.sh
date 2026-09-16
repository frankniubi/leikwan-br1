#!/bin/bash
export LC_ALL=C
set -u
APP="/Applications/LeiKwan Bridge.app"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
echo "--- install 1.0.33 arm64 ---"
pkill -f 'LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null
pkill -f 'Resources/bin/ultra-client' 2>/dev/null
sleep 3
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
mnt=$(hdiutil attach -readonly -nobrowse -noverify "$HOME/lkb-smoke/arm64-1.0.33.dmg" | grep -o '/Volumes/.*' | head -1)
rm -rf "$APP"
ditto "$mnt/LeiKwan Bridge.app" "$APP"
xattr -cr "$APP" 2>/dev/null
hdiutil detach "$mnt" >/dev/null 2>&1
echo -n "version: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist"
echo -n "runtime: "; shasum -a 256 "$APP/Contents/Resources/bin/ultra-client" | cut -c1-16
codesign --verify --deep --strict "$APP" >/dev/null 2>&1; echo "strict_verify=$?"
echo "--- plist validation (no root) ---"
cp "$HOME/mac-install-1033.sh" /tmp/lkb-install.sh 2>/dev/null || true
sh "$HOME/lkb-headless-install.sh" --print-plist --panel https://v3.leikwanhost.com --client-id 123 --agent-token dummy > /tmp/lkb-headless.plist 2>/tmp/lkb-plist.err
echo "print-plist exit=$?"
plutil -lint /tmp/lkb-headless.plist 2>&1 | head -2
/usr/libexec/PlistBuddy -c 'Print :Label' /tmp/lkb-headless.plist 2>&1
/usr/libexec/PlistBuddy -c 'Print :ProgramArguments' /tmp/lkb-headless.plist 2>&1 | head -8
echo "--- headless run (supervised + SIGTERM) ---"
CFG="$HOME/Library/Application Support/LeiKwan/ClientAgent/config.json"
python3 - "$CFG" <<'PY' > /tmp/lkb-identity.txt
import json, sys
d = json.load(open(sys.argv[1]))
cid = d.get("active_client_id") or d.get("ActiveClientId") or d.get("client_id") or d.get("ClientId")
tok = d.get("agent_token") or d.get("AgentToken") or ""
panel = d.get("master_url") or d.get("MasterUrl") or "https://v3.leikwanhost.com"
print(cid); print(tok); print(panel)
PY
CID=$(sed -n 1p /tmp/lkb-identity.txt)
TOK=$(sed -n 2p /tmp/lkb-identity.txt)
PANEL=$(sed -n 3p /tmp/lkb-identity.txt)
echo "client_id=$CID token_len=${#TOK} panel=$PANEL"
printf '%s' "$TOK" > /tmp/lkb-agent-token
chmod 600 /tmp/lkb-agent-token
rm -f /tmp/lkb-headless.log /tmp/lkb-headless-status.json
"$EXE" --headless --panel "$PANEL" --client-id "$CID" --agent-token-file /tmp/lkb-agent-token \
  --status-file /tmp/lkb-headless-status.json --log-file /tmp/lkb-headless.log --print-status \
  > /tmp/lkb-headless.out 2>&1 &
PID=$!
sleep 75
echo "--- during run ---"
echo -n "ultra processes: "; ps ax | grep -c '[R]esources/bin/ultra-client'
echo -n "status file: "; cat /tmp/lkb-headless-status.json 2>/dev/null | head -c 600; echo
echo "--- SIGTERM ---"
kill -TERM $PID
sleep 15
echo -n "still running: "; ps -p $PID >/dev/null 2>&1 && echo yes || echo no
echo "--- log tail ---"
grep -E 'headless_(start|connected|stopped|signal|connect_failed)|lease delivery|listener ready' /tmp/lkb-headless.log 2>/dev/null | tail -12
echo "--- stdout tail ---"
tail -4 /tmp/lkb-headless.out
echo MAC-HEADLESS-E2E-DONE
