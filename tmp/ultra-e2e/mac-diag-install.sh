#!/bin/bash
export LC_ALL=C
echo -n "sudo -n true: "; sudo -n true 2>&1; echo "exit=$?"
echo -n "whoami: "; whoami
echo -n "app present: "; ls -d "/Applications/LeiKwan Bridge.app" 2>&1
echo -n "writable by me: "; [ -w "/Applications/LeiKwan Bridge.app" ] && echo yes || echo "no"
echo -n "launchctl asuser test: "; launchctl asuser 501 id -u 2>&1 | head -2
echo "--- mount test ---"
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
mnt=$(hdiutil attach -readonly -nobrowse -noverify "$HOME/lkb-smoke/arm64-1.0.32.dmg" | grep -o '/Volumes/.*' | head -1)
echo "mnt=[$mnt]"
ls "$mnt" 2>&1 | head -3
echo -n "src app exists: "; ls -d "$mnt/LeiKwan Bridge.app" 2>&1
echo "--- copy attempt (no sudo, to a temp dir) ---"
rm -rf "$HOME/lkb-install-test"; mkdir -p "$HOME/lkb-install-test"
ditto "$mnt/LeiKwan Bridge.app" "$HOME/lkb-install-test/LeiKwan Bridge.app" 2>&1 | head -3
echo "copy exit=$?"
/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$HOME/lkb-install-test/LeiKwan Bridge.app/Contents/Info.plist" 2>&1
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
echo -n "disk free: "; df -h / | tail -1
echo DIAG-DONE
