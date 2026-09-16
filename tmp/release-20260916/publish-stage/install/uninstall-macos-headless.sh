#!/bin/sh
# Remove the windowless LeiKwan Bridge launchd daemon.
#   sudo sh uninstall-macos-headless.sh [--purge]
set -eu
LABEL="com.leikwan.bridge.headless"
PLIST="/Library/LaunchDaemons/$LABEL.plist"
STATE_DIR="/Library/Application Support/Leikwan/ClientAgent"
if [ "$(id -u)" != "0" ]; then
  echo "run with sudo" >&2
  exit 1
fi
launchctl bootout "system/$LABEL" 2>/dev/null || true
rm -f "$PLIST"
echo "removed $LABEL"
if [ "${1:-}" = "--purge" ]; then
  rm -rf "$STATE_DIR"
  echo "purged $STATE_DIR"
else
  echo "state kept in $STATE_DIR (use --purge to remove it)"
fi
