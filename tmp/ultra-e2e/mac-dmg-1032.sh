#!/bin/bash
set -uo pipefail
export LC_ALL=C
for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done
for pair in "arm64:173a794c1b67ad77" "x64:580cbb4892246ece"; do
  arch="${pair%%:*}"; expect="${pair##*:}"
  out="$HOME/lkb-smoke/b32-$arch"; rm -rf "$out"; mkdir -p "$out"
  ditto -x -k "$HOME/lkb-smoke/u32-$arch.zip" "$out"
  APP="$out/LeiKwan Bridge.app"
  xattr -cr "$APP"
  ln -sfn /Applications "$out/Applications"
  codesign --verify --deep --strict "$APP" >/dev/null 2>&1; code=$?
  ver=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist")
  rt=$(shasum -a 256 "$APP/Contents/Resources/bin/ultra-client" | cut -c1-16)
  echo "$arch bundle verify=$code ver=$ver runtime=$rt expect=$expect"
  [ "$ver" = "1.0.32" ] || echo "$arch VERSION-MISMATCH"
  [ "$rt" = "$expect" ] || echo "$arch RUNTIME-MISMATCH"
  dmg="$HOME/lkb-smoke/$arch-1.0.32.dmg"
  rm -f "$dmg"
  hdiutil create -volname "LeiKwan Bridge 1.0.32" -srcfolder "$out" -ov -format UDZO "$dmg" >/dev/null 2>&1
  echo "$arch dmg size=$(stat -f %z "$dmg") sha256=$(shasum -a 256 "$dmg" | cut -c1-64)"
  mnt=$(hdiutil attach -readonly -nobrowse -noverify "$dmg" | awk -F'\t' '/Apple_HFS/{print $NF}' | tail -1)
  codesign --verify --deep --strict "$mnt/LeiKwan Bridge.app" >/dev/null 2>&1; echo "$arch dmg-strict-verify=$?"
  echo -n "$arch dmg-root: "; ls "$mnt" | tr '\n' ' '; echo
  echo -n "$arch dmg-runtime: "; shasum -a 256 "$mnt/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client" | cut -c1-16
  hdiutil detach "$mnt" >/dev/null 2>&1
done
echo MAC-DMG-DONE
