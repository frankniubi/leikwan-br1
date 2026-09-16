#!/bin/bash
set -uo pipefail
export LC_ALL=C
detach_all() { for v in /Volumes/LeiKwan*; do [ -d "$v" ] && hdiutil detach "$v" >/dev/null 2>&1; done; }
verify() {
  local arch="$1" expect="$2"
  local dmg="$HOME/lkb-smoke/$arch-1.0.32.dmg"
  detach_all
  local mnt
  mnt=$(hdiutil attach -readonly -nobrowse -noverify "$dmg" | grep -o '/Volumes/.*' | head -1)
  echo "== $arch mnt=$mnt"
  local app="$mnt/LeiKwan Bridge.app"
  codesign --verify --deep --strict "$app" >/dev/null 2>&1; echo "   strict_verify=$?"
  echo "   version=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$app/Contents/Info.plist")"
  echo "   runtime=$(shasum -a 256 "$app/Contents/Resources/bin/ultra-client" | cut -c1-16) expect=$expect"
  echo "   apphost_arch=$(lipo -archs "$app/Contents/MacOS/LeikwanBridge.Mac")"
  echo "   applications_link=$(readlink "$mnt/Applications")"
  echo "   root_entries=$(ls "$mnt" | tr '\n' ',')"
  echo "   codesign_identity=$(codesign -dv "$app" 2>&1 | grep -i 'Signature' | head -2 | tr '\n' ' ')"
  detach_all
}
verify arm64 173a794c1b67ad77
verify x64 580cbb4892246ece
echo DMG-VERIFY-DONE
