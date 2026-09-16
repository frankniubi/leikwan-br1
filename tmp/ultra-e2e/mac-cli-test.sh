#!/bin/bash
export LC_ALL=C
set -u
APP="/Applications/LeiKwan Bridge.app"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
echo "--- install 1.0.34 ---"
pkill -f 'LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null; pkill -f 'Resources/bin/ultra-client' 2>/dev/null; sleep 3
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
mnt=$(hdiutil attach -readonly -nobrowse -noverify "$HOME/lkb-smoke/arm64-1.0.34.dmg" | grep -o '/Volumes/.*' | head -1)
rm -rf "$APP"; ditto "$mnt/LeiKwan Bridge.app" "$APP"; xattr -cr "$APP" 2>/dev/null
hdiutil detach "$mnt" >/dev/null 2>&1
echo -n "installed: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist"
codesign --verify --deep --strict "$APP" >/dev/null 2>&1; echo "strict_verify=$?"
echo "--- leikwan CLI (installed wrapper location) ---"
mkdir -p "$HOME/bin"
sed 's#exec sudo "$EXE"#exec "$EXE"#; s#exec "$EXE"#exec "$EXE"#' "$HOME/lkb-headless-install.sh" >/dev/null 2>&1 || true
cat > "$HOME/bin/leikwan" <<'WRAP'
#!/bin/sh
set -eu
APP="${LEIKWAN_APP:-/Applications/LeiKwan Bridge.app}"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
[ -x "$EXE" ] || { echo "leikwan: client bundle not found at $APP" >&2; exit 1; }
exec "$EXE" --cli "$@"
WRAP
chmod 755 "$HOME/bin/leikwan"
echo "### leikwan version"; "$HOME/bin/leikwan" version; echo "exit=$?"
echo "### leikwan help"; "$HOME/bin/leikwan" help | head -4
echo "### leikwan status (no daemon installed yet)"; "$HOME/bin/leikwan" status; echo "exit=$?"
echo "### leikwan doctor"; "$HOME/bin/leikwan" doctor; echo "exit=$?"
echo "### leikwan update --check"; "$HOME/bin/leikwan" update --check; echo "exit=$?"
echo "### leikwan logs (no log dir yet)"; "$HOME/bin/leikwan" logs -n 2 2>&1 | head -2; echo "exit=$?"
echo MAC-CLI-DONE
