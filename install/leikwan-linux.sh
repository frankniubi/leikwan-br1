#!/bin/sh
# leikwan — control the LeiKwan Bridge Linux client (leikwan-client-agent).
#
#   leikwan status | start | stop | restart | logs [-n N] [-f] | doctor | version
#   leikwan update [--check]
#
# The Linux client has always been windowless; this is the operator surface for
# it, matching the `leikwan` command the macOS and Windows no-GUI installs
# provide. Read-only commands need root because the agent's state (and its
# forward status files) live in root-owned directories.
set -eu

SERVICE="leikwan-client-agent.service"
SERVICE_NAME="leikwan-client-agent"
CONFIG="/etc/leikwan-client-agent/config.json"
STATE_DIR="/var/lib/leikwan-client-agent"
BIN_DIR="/usr/local/bin"
AGENT="$BIN_DIR/leikwan-client-agent.py"
UPDATER="$BIN_DIR/leikwan-client-agent-update"
FORWARD_STATUS_DIR="/run/ultra"

usage() {
  cat <<'USAGE'
usage: leikwan <status|start|stop|restart|logs|version|doctor|update> [options]
  status            service state, agent version and every forward
  start             start leikwan-client-agent
  stop              stop it (forwards are stopped cleanly)
  restart           stop then start
  logs [-n 40] [-f] journal of the client
  doctor            service, config, state directory and published version
  version           installed agent version
  update [--check]  update from the panel manifest (--check only reports)
USAGE
}

[ "$(id -u)" = "0" ] || exec sudo "$0" "$@"

agent_version() {
  [ -f "$AGENT" ] || { echo "unknown"; return; }
  sed -n 's/^AGENT_VERSION = "\(.*\)"/\1/p' "$AGENT" | head -n 1
}

config_value() {
  python3 - "$CONFIG" "$1" <<'PY' 2>/dev/null || true
import json, sys
try:
    data = json.load(open(sys.argv[1]))
except Exception:
    raise SystemExit(0)
for key in (sys.argv[2], sys.argv[2].upper(), "".join(part.capitalize() for part in sys.argv[2].split("_"))):
    value = data.get(key)
    if isinstance(value, str) and value.strip():
        print(value.strip())
        break
PY
}

service_state() {
  systemctl is-active --quiet "$SERVICE" 2>/dev/null && echo running || echo stopped
}

published_version() {
  base="$(config_value artifact_base)"
  [ -n "$base" ] || base="$(config_value master_url)"
  [ -n "$base" ] || return 0
  curl -fsSL --connect-timeout 15 --max-time 60 "$base/artifacts/versions.json" 2>/dev/null |
    python3 -c 'import json,sys;print(json.load(sys.stdin)["client_agent"]["version"])' 2>/dev/null || true
}

print_forwards() {
  python3 - "$FORWARD_STATUS_DIR" <<'PY'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
running = 0
total = 0
for path in sorted(root.glob("forward-*.status.json")):
    try:
        data = json.loads(path.read_text())
    except Exception:
        continue
    forward_id = data.get("forward_id") or path.name.split(".")[0].split("-")[-1]
    state = str(data.get("state") or data.get("status") or "unknown")
    attached = bool(data.get("active_udp")) or bool(data.get("latest_tcp"))
    total += 1
    if state in ("running", "active", "ready") or attached:
        running += 1
    print("  #%-5s %-9s %s" % (forward_id, "running" if (state in ("running", "active", "ready") or attached) else state, state))
print("FORWARD_COUNTS %d %d" % (running, total))
PY
}

case "${1:-help}" in
  status)
    service="$(service_state)"
    echo "platform : linux"
    echo "service  : $SERVICE_NAME — $service"
    echo "version  : $(agent_version)"
    counts="$(print_forwards)"
    echo "$counts" | grep -v '^FORWARD_COUNTS' || true
    read -r _ running total <<EOF
$(echo "$counts" | grep '^FORWARD_COUNTS')
EOF
    echo "forwards : ${running:-0}/${total:-0} running"
    if [ "$service" = "running" ] && [ "${running:-0}" -gt 0 ]; then
      exit 0
    fi
    exit 1
    ;;
  start|stop|restart)
    if [ "$1" = "restart" ]; then
      systemctl restart "$SERVICE"
    else
      systemctl "$1" "$SERVICE"
    fi
    echo "$1: ok"
    ;;
  logs)
    lines=40
    follow=""
    while [ $# -gt 1 ]; do
      case "$2" in
        -n|--lines) lines="$3"; shift 2 ;;
        -f|--follow) follow="-f"; shift ;;
        *) echo "unknown option: $2" >&2; exit 2 ;;
      esac
    done
    # shellcheck disable=SC2086
    exec journalctl -u "$SERVICE" -n "$lines" $follow --no-pager
    ;;
  version)
    agent_version
    ;;
  doctor)
    echo "service   : $(service_state) ($SERVICE_NAME)"
    echo "version   : $(agent_version)"
    echo "config    : $([ -f "$CONFIG" ] && echo present || echo missing) ($CONFIG)"
    echo "state     : $([ -d "$STATE_DIR" ] && echo present || echo missing) ($STATE_DIR)"
    echo "updater   : $([ -x "$UPDATER" ] && echo present || echo missing) ($UPDATER)"
    published="$(published_version)"
    echo "published : ${published:-unknown}"
    [ "$(service_state)" = "running" ] || exit 1
    ;;
  update)
    check_only=""
    [ "${2:-}" = "--check" ] && check_only="--check"
    installed="$(agent_version)"
    published="$(published_version)"
    echo "installed : $installed"
    echo "published : ${published:-unknown}"
    if [ -z "$published" ] || [ "$installed" = "$published" ]; then
      echo "update    : already current"
      exit 0
    fi
    if [ -n "$check_only" ]; then
      echo "update    : available (run \"leikwan update\" to apply)"
      exit 0
    fi
    if [ ! -x "$UPDATER" ]; then
      echo "update    : $UPDATER is missing; re-run the panel install command" >&2
      exit 1
    fi
    # The published updater downloads the manifest, verifies the installer and
    # agent hashes against it, reinstalls, and restarts the client once.
    exec "$UPDATER"
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    echo "unknown command: $1" >&2
    usage >&2
    exit 2
    ;;
esac
