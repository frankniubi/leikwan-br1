#!/bin/sh
# Install the windowless LeiKwan Bridge client as a launchd daemon.
#
#   sudo sh install-macos-headless.sh --panel https://v3.leikwanhost.com \
#        --client-id 123 --agent-token <token>
#
# The daemon runs the same application bundle as the windowed client, in its
# --headless lifecycle: no GUI session, no Avalonia, same Core services, same
# runtime attestation and update pinning. The installer is self-bootstrapping:
# it reads the public client manifest, downloads the correct architecture's
# self-contained DMG, verifies its size and SHA-256, installs the app bundle,
# and only then registers launchd. Remove it with --uninstall.
set -eu
INSTALLER_VERSION="1.0.45"

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
EGRESS_INTERFACE="${LEIKWAN_EGRESS_INTERFACE:-}"
MODE="install"
MANIFEST_FILE=""
DMG_FILE=""
MOUNT_POINT=""
NEW_APP=""
OLD_APP_BACKUP=""
SERVICE_WAS_LOADED=0

usage() {
  cat <<'USAGE'
usage: sudo sh install-macos-headless.sh --panel <url> --client-id <n> \
         (--agent-token <token> | --agent-token-file <path>) [--egress-interface <name>] [--app <bundle>]
       sudo sh install-macos-headless.sh --status
       sudo sh install-macos-headless.sh --uninstall
       sh install-macos-headless.sh --print-plist [--panel <url>] [--client-id <n>] \
         [--agent-token <token> | --agent-token-file <path>]   # plist to stdout, no root needed
USAGE
}

cleanup_downloads() {
  status=$?
  trap - EXIT INT TERM
  if [ -n "$MOUNT_POINT" ]; then
    hdiutil detach "$MOUNT_POINT" >/dev/null 2>&1 || true
  fi
  [ -z "$MOUNT_POINT" ] || rmdir "$MOUNT_POINT" >/dev/null 2>&1 || true
  [ -z "$MANIFEST_FILE" ] || rm -f "$MANIFEST_FILE"
  [ -z "$DMG_FILE" ] || rm -f "$DMG_FILE"
  if [ "$status" -ne 0 ] && [ -n "$NEW_APP" ] && [ -e "$NEW_APP" ]; then
    rm -rf "$NEW_APP"
  fi
  if [ "$status" -ne 0 ] && [ -n "$OLD_APP_BACKUP" ] && [ -e "$OLD_APP_BACKUP" ] && [ ! -e "$APP" ]; then
    mv "$OLD_APP_BACKUP" "$APP" || true
  fi
  if [ "$status" -ne 0 ] && [ "$SERVICE_WAS_LOADED" = "1" ]; then
    launchctl bootstrap system "$PLIST" >/dev/null 2>&1 || true
    launchctl kickstart -k "system/$LABEL" >/dev/null 2>&1 || true
  fi
  exit "$status"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --panel) PANEL="${2:-}"; shift 2 ;;
    --client-id) CLIENT_ID="${2:-}"; shift 2 ;;
    --agent-token) TOKEN="${2:-}"; shift 2 ;;
    --agent-token-file) TOKEN_SOURCE="${2:-}"; shift 2 ;;
    --egress-interface) EGRESS_INTERFACE="${2:-}"; shift 2 ;;
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

trap cleanup_downloads EXIT INT TERM

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

require_command() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "required macOS command is missing: $1" >&2
    exit 1
  }
}

for command_name in curl shasum hdiutil ditto plutil launchctl xattr mktemp stat awk grep sed \
  id uname mkdir rm mv rmdir dirname tr chmod chown; do
  require_command "$command_name"
done

manifest_value() {
  # plutil is part of macOS and can read JSON without requiring Homebrew,
  # Python, jq, or any other third-party dependency.
  plutil -extract "$1" raw -o - "$MANIFEST_FILE" 2>/dev/null
}

absolute_url() {
  case "$1" in
    http://*|https://*) printf '%s' "$1" ;;
    /*) printf '%s%s' "${PANEL%/}" "$1" ;;
    *) printf '%s/%s' "${PANEL%/}" "$1" ;;
  esac
}

file_size() {
  stat -f '%z' "$1" 2>/dev/null || stat -c '%s' "$1"
}

download_verified() {
  url="$1"
  output="$2"
  expected_size="$3"
  expected_sha="$4"
  curl -fL --retry 3 --retry-all-errors --connect-timeout 20 \
    --speed-time 30 --speed-limit 1024 -o "$output" "$url"
  actual_size="$(file_size "$output")"
  [ "$actual_size" = "$expected_size" ] || {
    echo "download size mismatch: expected $expected_size, got $actual_size" >&2
    exit 1
  }
  actual_sha="$(shasum -a 256 "$output" | awk '{print tolower($1)}')"
  [ "$actual_sha" = "$(printf '%s' "$expected_sha" | tr '[:upper:]' '[:lower:]')" ] || {
    echo "download SHA-256 mismatch" >&2
    exit 1
  }
}

ensure_app_bundle() {
  case "$(uname -m)" in
    arm64|aarch64) manifest_key="macos_client_app_arm64" ;;
    x86_64|amd64) manifest_key="macos_client_app_x64" ;;
    *) echo "unsupported macOS architecture: $(uname -m)" >&2; exit 1 ;;
  esac

  MANIFEST_FILE="$(mktemp "${TMPDIR:-/tmp}/leikwan-client-manifest.XXXXXX.json")"
  curl -fL --retry 3 --retry-all-errors --connect-timeout 20 \
    --speed-time 30 --speed-limit 1024 \
    -o "$MANIFEST_FILE" "${PANEL%/}/artifacts/client-manifest.json"

  version="$(manifest_value "manifest.$manifest_key.version")"
  artifact_path="$(manifest_value "manifest.$manifest_key.path")"
  artifact_url="$(manifest_value "manifest.$manifest_key.artifact_url")"
  expected_sha="$(manifest_value "manifest.$manifest_key.sha256")"
  expected_size="$(manifest_value "manifest.$manifest_key.size_bytes")"
  [ -n "$version" ] && [ -n "$artifact_path" ] && [ -n "$artifact_url" ] \
    && printf '%s' "$expected_sha" | grep -Eq '^[0-9A-Fa-f]{64}$' \
    && printf '%s' "$expected_size" | grep -Eq '^[0-9]+$' || {
      echo "macOS client entry is incomplete in the panel manifest" >&2
      exit 1
    }

  current_version=""
  if [ -f "$APP/Contents/Info.plist" ]; then
    current_version="$(plutil -extract CFBundleShortVersionString raw -o - "$APP/Contents/Info.plist" 2>/dev/null || true)"
  fi
  if [ "$current_version" = "$version" ] \
    && [ -x "$EXECUTABLE" ] \
    && [ -d "$APP/Contents/Resources/dotnet" ] \
    && [ -d "$APP/Contents/Resources/bin" ]; then
    echo "macOS app $version already installed at $APP"
    return 0
  fi

  echo "downloading macOS app $version ($manifest_key)"
  DMG_FILE="$(mktemp "${TMPDIR:-/tmp}/leikwan-client.XXXXXX.dmg")"
  download_verified "$(absolute_url "$artifact_url")" "$DMG_FILE" "$expected_size" "$expected_sha"
  MOUNT_POINT="$(mktemp -d "${TMPDIR:-/tmp}/leikwan-client-mount.XXXXXX")"
  hdiutil attach -readonly -nobrowse -mountpoint "$MOUNT_POINT" "$DMG_FILE" >/dev/null
  source_app="$MOUNT_POINT/LeiKwan Bridge.app"
  [ -x "$source_app/Contents/MacOS/LeikwanBridge.Mac" ] || {
    echo "downloaded DMG does not contain a runnable LeiKwan Bridge.app" >&2
    exit 1
  }
  # The DMG must carry the complete self-contained runtime. Do not register
  # launchd against a framework-dependent or partially copied bundle.
  [ -d "$source_app/Contents/Resources/dotnet" ] || {
    echo "downloaded app is missing its bundled .NET runtime" >&2
    exit 1
  }
  [ -d "$source_app/Contents/Resources/bin" ] || {
    echo "downloaded app is missing its bundled helper binaries" >&2
    exit 1
  }
  for helper in sing-box wstunnel leikwan-bond leikwan-ingress leikwan-uot-bond ultra-client; do
    [ -x "$source_app/Contents/Resources/bin/$helper" ] || {
      echo "downloaded app is missing helper binary: $helper" >&2
      exit 1
    }
  done
  mounted_version="$(plutil -extract CFBundleShortVersionString raw -o - "$source_app/Contents/Info.plist" 2>/dev/null || true)"
  [ "$mounted_version" = "$version" ] || {
    echo "downloaded app version mismatch: expected $version, got $mounted_version" >&2
    exit 1
  }

  if launchctl print "system/$LABEL" >/dev/null 2>&1; then
    SERVICE_WAS_LOADED=1
    launchctl bootout "system/$LABEL" >/dev/null 2>&1 || true
  fi
  app_parent="$(dirname "$APP")"
  mkdir -p "$app_parent"
  NEW_APP="$app_parent/.LeiKwan Bridge.app.new.$$"
  rm -rf "$NEW_APP"
  ditto "$source_app" "$NEW_APP"
  hdiutil detach "$MOUNT_POINT" >/dev/null
  rmdir "$MOUNT_POINT" >/dev/null 2>&1 || true
  MOUNT_POINT=""

  if [ -e "$APP" ]; then
    OLD_APP_BACKUP="$app_parent/.LeiKwan Bridge.app.backup.$$.${current_version:-unknown}"
    mv "$APP" "$OLD_APP_BACKUP"
  fi
  mv "$NEW_APP" "$APP"
  NEW_APP=""
  xattr -dr com.apple.quarantine "$APP" 2>/dev/null || true
  EXECUTABLE="$APP/Contents/MacOS/LeikwanBridge.Mac"
  echo "installed macOS app $version at $APP"
}

if [ "$MODE" != "print" ]; then
  ensure_app_bundle
fi

write_plist() {
  egress_plist_args=""
  if [ -n "$EGRESS_INTERFACE" ]; then
    egress_plist_args="    <string>--egress-interface</string>
    <string>$EGRESS_INTERFACE</string>"
  fi
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
$egress_plist_args
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
  echo "the self-bootstrapping installer could not install the macOS app bundle" >&2
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
