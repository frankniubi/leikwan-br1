#!/bin/sh
# Install the windowless LeiKwan Bridge client as a launchd daemon.
#
#   sudo sh install-macos-headless.sh --panel https://v3.leikwanhost.com \
#        --client-id 123 --agent-token <token>
#
# The daemon runs the same application bundle as the windowed client, in its
# --headless lifecycle: no GUI session, no Avalonia, same Core services, same
# runtime attestation and update pinning. The installer is self-bootstrapping:
# it reads the public client manifest, downloads the Apple Silicon (arm64)
# self-contained DMG, verifies its size and SHA-256, installs the app bundle,
# and only then registers launchd. Remove it with --uninstall.
#
# Re-running it is an upgrade/repair: the running daemon is booted out and
# waited for, the plist and bundle are replaced (the previous ones are kept
# until the new daemon is verified running), the daemon is bootstrapped again
# and must stay running for a few seconds. Any failure restores the previous
# bundle and plist and restarts the previous daemon.
#
# launchd supervision: KeepAlive restarts the daemon after a crash or any
# non-zero exit (a deliberate "leikwan stop" exits 0 and stays stopped),
# ThrottleInterval spaces restarts, and because AbandonProcessGroup is off,
# launchd also kills the ultra-client children in the daemon's process group
# when the daemon dies, so a crash cannot leave orphaned runtimes behind.
# Soft/HardResourceLimits raise the open-file limit (NumberOfFiles) from
# launchd's default 256, which a busy forward set exhausts with sockets.
#
# Self-update: the daemon checks the panel manifest on its own (first check
# 1-10 min after start, then every ~6 h, backoff on failure) and applies the
# verified update zip through a detached helper that boots the daemon out,
# swaps the bundle (root-only backups in $STATE_DIR/backups, newest 3 kept),
# restarts it and rolls back if it does not come up connected. Set
# "disable_auto_updates": true in the daemon config to turn it off.
set -eu
INSTALLER_VERSION="1.0.57"

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
PLIST_BACKUP=""
PLIST_STAGED=""
PLIST_CREATED=0
TOKEN_BACKUP=""
INSTALL_COMMITTED=0
# Set right before the first change to the machine; nothing is rolled back
# for a run that failed earlier (bad arguments, download or checksum errors).
CHANGES_STARTED=0
# Seconds the new daemon must keep the same pid before the install counts.
VERIFY_STABLE_SECONDS="${LEIKWAN_VERIFY_STABLE_SECONDS:-5}"

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

service_loaded() {
  launchctl print "system/$LABEL" >/dev/null 2>&1
}

# bootout returns before launchd has finished tearing the job down; a
# bootstrap issued in that window fails with "Bootstrap failed: 5: Input/output
# error". Wait until the label is really gone.
wait_unloaded() {
  waited=0
  while service_loaded; do
    if [ "$waited" -ge 30 ]; then
      return 1
    fi
    sleep 1
    waited=$((waited + 1))
  done
  return 0
}

stop_service() {
  if service_loaded; then
    launchctl bootout "system/$LABEL" >/dev/null 2>&1 || true
  fi
  wait_unloaded
}

bootstrap_service() {
  # A label disabled with "launchctl disable" cannot be bootstrapped.
  launchctl enable "system/$LABEL" >/dev/null 2>&1 || true
  attempt=1
  while [ "$attempt" -le 5 ]; do
    if launchctl bootstrap system "$PLIST" 2>/dev/null; then
      return 0
    fi
    # Already loaded (a concurrent bootstrap won the race) is success too.
    service_loaded && return 0
    sleep 2
    attempt=$((attempt + 1))
  done
  launchctl bootstrap system "$PLIST"
}

service_pid() {
  launchctl print "system/$LABEL" 2>/dev/null \
    | awk '/^[[:space:]]*pid = [0-9]+/ { print $3; exit }'
}

service_running() {
  launchctl print "system/$LABEL" 2>/dev/null | grep -q 'state = running'
}

# The daemon counts as installed only when launchd reports it running and the
# same pid survives VERIFY_STABLE_SECONDS (a crash loop changes the pid or
# leaves the job waiting out its ThrottleInterval).
verify_running() {
  waited=0
  pid=""
  while [ "$waited" -lt 30 ]; do
    if service_running; then
      pid="$(service_pid)"
      [ -n "$pid" ] && break
    fi
    sleep 1
    waited=$((waited + 1))
  done
  if [ -z "$pid" ]; then
    echo "launchd never reported $LABEL running" >&2
    return 1
  fi
  sleep "$VERIFY_STABLE_SECONDS"
  if ! service_running || [ "$(service_pid)" != "$pid" ]; then
    echo "$LABEL did not stay running (pid $pid)" >&2
    launchctl print "system/$LABEL" 2>/dev/null | grep -E 'state =|pid =|last exit code' >&2 || true
    return 1
  fi
  echo "verified $LABEL running (pid $pid)"
  return 0
}

# Undo a failed install/upgrade: previous bundle, previous plist, previous
# daemon. Every step is best effort; the goal is to leave the machine in the
# state it was in before the installer ran.
rollback() {
  echo "install-macos-headless: install failed; restoring the previous installation" >&2
  launchctl bootout "system/$LABEL" >/dev/null 2>&1 || true
  wait_unloaded || true
  if [ -n "$OLD_APP_BACKUP" ] && [ -e "$OLD_APP_BACKUP" ]; then
    if [ -e "$APP" ]; then
      failed_app="$(dirname "$APP")/.LeiKwan Bridge.app.failed.$$"
      rm -rf "$failed_app"
      mv "$APP" "$failed_app" && rm -rf "$failed_app"
    fi
    mv "$OLD_APP_BACKUP" "$APP" && echo "restored previous app bundle" >&2
  fi
  if [ -n "$TOKEN_BACKUP" ] && [ -f "$TOKEN_BACKUP" ]; then
    mv -f "$TOKEN_BACKUP" "$TOKEN_FILE"
  fi
  if [ -n "$PLIST_BACKUP" ] && [ -f "$PLIST_BACKUP" ]; then
    mv -f "$PLIST_BACKUP" "$PLIST" && echo "restored previous launchd plist" >&2
  elif [ "$PLIST_CREATED" = "1" ]; then
    rm -f "$PLIST"
  fi
  if [ "$SERVICE_WAS_LOADED" = "1" ] && [ -f "$PLIST" ]; then
    if bootstrap_service >/dev/null 2>&1; then
      launchctl kickstart "system/$LABEL" >/dev/null 2>&1 || true
      echo "restarted the previous $LABEL" >&2
    else
      echo "could not restart the previous $LABEL; check: launchctl print system/$LABEL" >&2
    fi
  fi
}

cleanup_downloads() {
  status=$?
  trap - EXIT INT TERM
  set +e
  if [ -n "$MOUNT_POINT" ]; then
    hdiutil detach "$MOUNT_POINT" >/dev/null 2>&1 || true
  fi
  [ -z "$MOUNT_POINT" ] || rmdir "$MOUNT_POINT" >/dev/null 2>&1 || true
  [ -z "$MANIFEST_FILE" ] || rm -f "$MANIFEST_FILE"
  [ -z "$DMG_FILE" ] || rm -f "$DMG_FILE"
  [ -z "$PLIST_STAGED" ] || rm -f "$PLIST_STAGED"
  if [ "$status" -ne 0 ] && [ -n "$NEW_APP" ] && [ -e "$NEW_APP" ]; then
    rm -rf "$NEW_APP"
  fi
  if [ "$status" -ne 0 ] && [ "$CHANGES_STARTED" = "1" ] && [ "$INSTALL_COMMITTED" != "1" ]; then
    rollback
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
  launchctl print "system/$LABEL" 2>/dev/null | grep -E 'state =|pid =|last exit code|runs =' || echo "service: not loaded"
  if [ -f "$PLIST" ]; then
    echo "launchd plist ($PLIST):"
    plutil -p "$PLIST" 2>/dev/null | grep -E -A3 '"(KeepAlive|RunAtLoad|ThrottleInterval|AbandonProcessGroup|SoftResourceLimits|HardResourceLimits)"' || true
  fi
  if [ -f "$STATUS_FILE" ]; then
    echo "status:"
    cat "$STATUS_FILE"
  fi
}

uninstall() {
  # bootout also stops the ultra-client runtimes in the daemon's process group.
  stop_service || echo "warning: launchd still lists $LABEL" >&2
  rm -f "$PLIST"
  if [ -f /usr/local/bin/leikwan ] && grep -q 'LeikwanBridge.Mac' /usr/local/bin/leikwan 2>/dev/null; then
    rm -f /usr/local/bin/leikwan
  fi
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

# True when dotted numeric version $1 >= $2 (missing parts count as 0).
version_at_least() {
  awk -v a="$1" -v b="$2" 'BEGIN {
    na = split(a, x, "."); nb = split(b, y, "."); n = (na > nb) ? na : nb
    for (i = 1; i <= n; i++) {
      if (x[i] !~ /^[0-9]+$/) x[i] = 0; if (y[i] !~ /^[0-9]+$/) y[i] = 0
      if (x[i] + 0 > y[i] + 0) exit 0
      if (x[i] + 0 < y[i] + 0) exit 1
    }
    exit 0
  }'
}

ensure_app_bundle() {
  case "$(uname -m)" in
    arm64|aarch64) manifest_key="macos_client_app_arm64" ;;
    x86_64|amd64)
      echo "install-macos-headless: Apple Silicon (arm64) required: the LeiKwan Bridge macOS client no longer supports Intel (x86_64) Macs" >&2
      exit 1
      ;;
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
  # Never downgrade: a bundle at least as new as the published one (an update
  # the daemon applied itself, or a candidate under test) is kept as it is.
  if [ -n "$current_version" ] \
    && version_at_least "$current_version" "$version" \
    && [ -x "$EXECUTABLE" ] \
    && [ -d "$APP/Contents/Resources/dotnet" ] \
    && [ -d "$APP/Contents/Resources/bin" ]; then
    echo "macOS app $current_version already installed at $APP (published: $version)"
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

  # From here on the machine changes; a failure restores what was there.
  CHANGES_STARTED=1
  stop_service || {
    echo "launchd did not unload $LABEL within 30 s; not replacing the bundle under it" >&2
    exit 1
  }
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
  # Remember whether a daemon was loaded before anything changes, so a failed
  # upgrade restarts it and a failed fresh install leaves nothing behind.
  if service_loaded; then
    SERVICE_WAS_LOADED=1
  fi
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
  <key>ExitTimeOut</key>
  <integer>60</integer>
  <key>SoftResourceLimits</key>
  <dict>
    <key>NumberOfFiles</key>
    <integer>65536</integer>
  </dict>
  <key>HardResourceLimits</key>
  <dict>
    <key>NumberOfFiles</key>
    <integer>65536</integer>
  </dict>
  <key>AbandonProcessGroup</key>
  <false/>
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

CHANGES_STARTED=1
mkdir -p "$STATE_DIR" "$LOG_DIR"
chmod 700 "$STATE_DIR"
chmod 755 "$LOG_DIR"
umask 077
if [ -f "$TOKEN_FILE" ]; then
  TOKEN_BACKUP="$TOKEN_FILE.previous.$$"
  cp -p "$TOKEN_FILE" "$TOKEN_BACKUP"
fi
printf '%s' "$TOKEN" > "$TOKEN_FILE.new.$$"
chmod 600 "$TOKEN_FILE.new.$$"
chown root:wheel "$TOKEN_FILE.new.$$" 2>/dev/null || true
mv -f "$TOKEN_FILE.new.$$" "$TOKEN_FILE"

# A downloaded bundle carries the quarantine bit; launchd cannot start a
# quarantined binary, so clear it for this app only.
xattr -dr com.apple.quarantine "$APP" 2>/dev/null || true

# Stage and lint the new plist before touching the installed one.
PLIST_STAGED="$PLIST.new.$$"
write_plist > "$PLIST_STAGED"
chmod 644 "$PLIST_STAGED"
chown root:wheel "$PLIST_STAGED" 2>/dev/null || true
plutil -lint "$PLIST_STAGED" >/dev/null

stop_service || {
  echo "launchd did not unload $LABEL within 30 s" >&2
  exit 1
}
if [ -f "$PLIST" ]; then
  PLIST_BACKUP="$PLIST.previous.$$"
  cp -p "$PLIST" "$PLIST_BACKUP"
else
  PLIST_CREATED=1
fi
mv -f "$PLIST_STAGED" "$PLIST"
PLIST_STAGED=""

bootstrap_service
# RunAtLoad starts it; kickstart (without -k) only covers a label launchd kept
# stopped, and never kills the instance that is just starting.
launchctl kickstart "system/$LABEL" >/dev/null 2>&1 || true
verify_running

# The new daemon is up: drop the rollback material.
INSTALL_COMMITTED=1
[ -z "$PLIST_BACKUP" ] || rm -f "$PLIST_BACKUP"
PLIST_BACKUP=""
[ -z "$TOKEN_BACKUP" ] || rm -f "$TOKEN_BACKUP"
TOKEN_BACKUP=""
if [ -n "$OLD_APP_BACKUP" ] && [ -e "$OLD_APP_BACKUP" ]; then
  rm -rf "$OLD_APP_BACKUP"
fi
OLD_APP_BACKUP=""

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
