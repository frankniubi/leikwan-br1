#!/bin/sh
# Install the windowless LeiKwan Bridge client as a launchd daemon.
#
#   sudo sh install-macos-headless.sh --panel https://v3.leikwanhost.com \
#        --client-id 123 --agent-token <token>
#
# The daemon runs the same signed application bundle as the windowed client, in
# its --headless lifecycle: no GUI session, no Avalonia, same Core services,
# same runtime attestation and update pinning. Remove it with --uninstall.
set -eu

LABEL="com.leikwan.bridge.headless"
PLIST="/Library/LaunchDaemons/$LABEL.plist"
APP="${LEIKWAN_APP:-/Applications/LeiKwan Bridge.app}"
EXECUTABLE="$APP/Contents/MacOS/LeikwanBridge.Mac"
STATE_DIR="/Library/Application Support/Leikwan/ClientAgent"
TOKEN_FILE="$STATE_DIR/agent-token"
STATUS_FILE="$STATE_DIR/headless-status.json"
LOG_DIR="/Library/Logs/Leikwan/ClientAgent"
PANEL=""
CLIENT_ID=""
TOKEN=""
TOKEN_SOURCE=""
MODE="install"

usage() {
  cat <<'USAGE'
usage: sudo sh install-macos-headless.sh --panel <url> --client-id <n> \
         (--agent-token <token> | --agent-token-file <path>) [--app <bundle>]
       sudo sh install-macos-headless.sh --status
       sudo sh install-macos-headless.sh --uninstall
       sh install-macos-headless.sh --print-plist [--panel <url>] [--client-id <n>] \
         [--agent-token <token> | --agent-token-file <path>]   # plist to stdout, no root needed
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --panel) PANEL="${2:-}"; shift 2 ;;
    --client-id) CLIENT_ID="${2:-}"; shift 2 ;;
    --agent-token) TOKEN="${2:-}"; shift 2 ;;
    --agent-token-file) TOKEN_SOURCE="${2:-}"; shift 2 ;;
    --app) APP="${2:-}"; EXECUTABLE="$APP/Contents/MacOS/LeikwanBridge.Mac"; shift 2 ;;
    --status) MODE="status"; shift ;;
    --uninstall) MODE="uninstall"; shift ;;
    --print-plist) MODE="print"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [ "$MODE" != "print" ] && [ "$(id -u)" != "0" ]; then
  echo "install-macos-headless: run with sudo (a LaunchDaemon must be owned by root)" >&2
  exit 1
fi

status() {
  if [ -f "$PLIST" ]; then
    echo "plist: $PLIST"
  else
    echo "plist: missing"
  fi
  launchctl print "system/$LABEL" 2>/dev/null | grep -E 'state =|pid =|last exit code' || echo "service: not loaded"
  if [ -f "$STATUS_FILE" ]; then
    echo "status:"
    cat "$STATUS_FILE"
  fi
}

uninstall() {
  launchctl bootout "system/$LABEL" 2>/dev/null || true
  rm -f "$PLIST"
  echo "removed $LABEL"
  echo "kept state in $STATE_DIR (delete it to forget the client identity)"
}

if [ "$MODE" = "status" ]; then
  status
  exit 0
fi

if [ "$MODE" = "uninstall" ]; then
  uninstall
  exit 0
fi

if [ -n "$TOKEN_SOURCE" ]; then
  if [ ! -r "$TOKEN_SOURCE" ]; then
    echo "agent token file is not readable: $TOKEN_SOURCE" >&2
    exit 1
  fi
  TOKEN="$(tr -d '\r\n' < "$TOKEN_SOURCE")"
fi

if [ -z "$PANEL" ] || [ -z "$CLIENT_ID" ] || [ -z "$TOKEN" ]; then
  echo "panel, client-id and an agent token are required" >&2
  usage >&2
  exit 2
fi

case "$CLIENT_ID" in
  ''|*[!0-9]*) echo "client id must be numeric" >&2; exit 2 ;;
esac

write_plist() {
cat <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>$LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>$EXECUTABLE</string>
    <string>--headless</string>
    <string>--panel</string>
    <string>$PANEL</string>
    <string>--client-id</string>
    <string>$CLIENT_ID</string>
    <string>--agent-token-file</string>
    <string>$TOKEN_FILE</string>
    <string>--status-file</string>
    <string>$STATUS_FILE</string>
    <string>--log-file</string>
    <string>$LOG_DIR/headless-.log</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <dict>
    <key>SuccessfulExit</key>
    <false/>
  </dict>
  <key>ThrottleInterval</key>
  <integer>15</integer>
  <key>StandardOutPath</key>
  <string>$LOG_DIR/launchd.out.log</string>
  <key>StandardErrorPath</key>
  <string>$LOG_DIR/launchd.err.log</string>
  <key>ProcessType</key>
  <string>Interactive</string>
</dict>
</plist>
PLIST
}

# --print-plist emits the LaunchDaemon so configuration management (or a
# reviewer) can inspect it without root and without touching the machine.
if [ "$MODE" = "print" ]; then
  write_plist
  exit 0
fi

if [ ! -x "$EXECUTABLE" ]; then
  echo "client bundle not found: $APP" >&2
  echo "install the macOS app first (LeiKwan Bridge 1.0.32 or newer)" >&2
  exit 1
fi

mkdir -p "$STATE_DIR" "$LOG_DIR"
chmod 700 "$STATE_DIR"
umask 077
printf '%s' "$TOKEN" > "$TOKEN_FILE"
chmod 600 "$TOKEN_FILE"
chown root:wheel "$TOKEN_FILE" 2>/dev/null || true

# A downloaded bundle carries the quarantine bit; launchd cannot start a
# quarantined binary, so clear it for this app only.
xattr -dr com.apple.quarantine "$APP" 2>/dev/null || true

write_plist > "$PLIST"

chmod 644 "$PLIST"
chown root:wheel "$PLIST" 2>/dev/null || true
plutil -lint "$PLIST" >/dev/null

launchctl bootout "system/$LABEL" 2>/dev/null || true
launchctl bootstrap system "$PLIST"
launchctl enable "system/$LABEL" 2>/dev/null || true
launchctl kickstart -k "system/$LABEL"

# `leikwan` is the operator surface for this daemon: status/start/stop/restart/
# logs/doctor/update, implemented by the same binary so it cannot drift from the
# client it controls.
mkdir -p /usr/local/bin
cat > /usr/local/bin/leikwan <<'LEIKWAN'
#!/bin/sh
set -eu
APP="${LEIKWAN_APP:-/Applications/LeiKwan Bridge.app}"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
if [ ! -x "$EXE" ]; then
  echo "leikwan: client bundle not found at $APP" >&2
  exit 1
fi
case "${1:-}" in
  start|stop|restart|update)
    if [ "$(id -u)" = "0" ]; then exec "$EXE" --cli "$@"; fi
    exec sudo "$EXE" --cli "$@"
    ;;
  *) exec "$EXE" --cli "$@" ;;
esac
LEIKWAN
chmod 755 /usr/local/bin/leikwan
echo "cli: /usr/local/bin/leikwan (try: leikwan status)"

echo "installed $LABEL"
echo "status file: $STATUS_FILE"
echo "log: $LOG_DIR/headless-*.log"
echo "check with: sudo sh install-macos-headless.sh --status"