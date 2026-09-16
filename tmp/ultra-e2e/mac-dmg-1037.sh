#!/bin/bash
set -uo pipefail
export LC_ALL=C
VER=1.0.37
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
rm -f "$HOME/lkb-smoke/arm64-$VER.dmg" "$HOME/lkb-smoke/x64-$VER.dmg"
for pair in "arm64:b92a4e3425152307" "x64:fc84b9d256f8b968"; do
  arch="${pair%%:*}"; expect="${pair##*:}"
  out="$HOME/lkb-smoke/b37-$arch"; rm -rf "$out"; mkdir -p "$out"
  ditto -x -k "$HOME/lkb-smoke/u37-$arch.zip" "$out"
  APP="$out/LeiKwan Bridge.app"
  xattr -cr "$APP"
  ln -sfn /Applications "$out/Applications"
  codesign --verify --deep --strict "$APP" >/dev/null 2>&1; code=$?
  ver=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist")
  rt=$(shasum -a 256 "$APP/Contents/Resources/bin/ultra-client" | cut -c1-16)
  echo "$arch bundle verify=$code ver=$ver runtime=$rt expect=$expect"
  [ "$ver" = "$VER" ] || echo "$arch VERSION-MISMATCH"
  [ "$rt" = "$expect" ] || echo "$arch RUNTIME-MISMATCH"
  dmg="$HOME/lkb-smoke/$arch-$VER.dmg"
  rm -f "$dmg"
  hdiutil create -volname "LeiKwan Bridge $VER" -srcfolder "$out" -ov -format UDZO "$dmg" >/dev/null 2>&1
  echo "$arch dmg size=$(stat -f %z "$dmg") sha256=$(shasum -a 256 "$dmg" | cut -c1-64)"
  mnt=$(hdiutil attach -readonly -nobrowse -noverify "$dmg" | grep -o '/Volumes/.*' | head -1)
  codesign --verify --deep --strict "$mnt/LeiKwan Bridge.app" >/dev/null 2>&1; echo "$arch dmg-strict-verify=$?"
  echo -n "$arch dmg-root: "; ls "$mnt" | tr '\n' ' '; echo
  echo -n "$arch dmg-version: "; /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$mnt/LeiKwan Bridge.app/Contents/Info.plist"
  echo -n "$arch dmg-runtime: "; shasum -a 256 "$mnt/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client" | cut -c1-64
  hdiutil detach "$mnt" >/dev/null 2>&1
done
echo MAC-DMG-1037-DONE
