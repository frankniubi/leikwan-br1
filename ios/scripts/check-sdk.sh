#!/usr/bin/env bash
# Fails unless the selected Xcode builds against the iOS 27.1 SDK or later.
# Built with an older SDK the app runs letterboxed on iPhone Duo, and the fold API used by
# App/Design/FoldLayout.swift (reserved regions) does not exist.
set -euo pipefail

required="${IOS_SDK_MINIMUM:-27.1}"

xcodebuild -version
sdk="$(xcrun --sdk iphoneos --show-sdk-version)"
echo "iphoneos SDK $sdk (need $required or later)"

lowest="$(printf '%s\n%s\n' "$required" "$sdk" | sort -t. -k1,1n -k2,2n -k3,3n | head -1)"
if [ "$lowest" != "$required" ]; then
  echo "iOS SDK $sdk is older than $required: select Xcode 27.1 or later (DEVELOPER_DIR)" >&2
  exit 1
fi
