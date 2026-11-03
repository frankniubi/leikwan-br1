#!/bin/sh
# Remove the windowless LeiKwan Bridge launchd daemon.
#   sudo sh uninstall-macos-headless.sh [--purge]
#
# bootout stops the daemon and, because the plist leaves AbandonProcessGroup
# off, launchd also stops the ultra-client runtimes in its process group. The
# script waits until launchd has really released the label, so an immediate
# re-install does not race the teardown.
set -eu
LABEL="com.leikwan.bridge.headless"
PLIST="/Library/LaunchDaemons/$LABEL.plist"
STATE_DIR="/Library/Application Support/Leikwan/ClientAgent"
CLI="/usr/local/bin/leikwan"
if [ "$(id -u)" != "0" ]; then
  echo "run with sudo" >&2
  exit 1
fi
launchctl bootout "system/$LABEL" 2>/dev/null || true
waited=0
while launchctl print "system/$LABEL" >/dev/null 2>&1; do
  if [ "$waited" -ge 30 ]; then
    echo "warning: launchd still lists $LABEL after 30 s" >&2
    break
  fi
  sleep 1
  waited=$((waited + 1))
done
rm -f "$PLIST"
# Only remove the CLI shim this installer wrote.
if [ -f "$CLI" ] && grep -q 'LeikwanBridge.Mac' "$CLI" 2>/dev/null; then
  rm -f "$CLI"
fi
echo "removed $LABEL"
if [ "${1:-}" = "--purge" ]; then
  rm -rf "$STATE_DIR"
  echo "purged $STATE_DIR"
else
  echo "state kept in $STATE_DIR (use --purge to remove it)"
fi
