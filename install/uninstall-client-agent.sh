#!/usr/bin/env bash
set -euo pipefail

CONFIG_DIR="/etc/leikwan-client-agent"
STATE_DIR="/var/lib/leikwan-client-agent"
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-client-agent"
PURGE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --state-dir) STATE_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --purge) PURGE=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

systemctl stop "$SERVICE_NAME.service" 2>/dev/null || true
systemctl disable "$SERVICE_NAME.service" 2>/dev/null || true
rm -f "/etc/systemd/system/$SERVICE_NAME.service"
systemctl daemon-reload || true

if [[ "$PURGE" == "1" ]]; then
  rm -rf "$CONFIG_DIR" "$STATE_DIR"
  rm -f \
    "$BIN_DIR/leikwan-client-agent.py" \
    "$BIN_DIR/install_forward_runtime.py" \
    "$BIN_DIR/leikwan-rate-limit.py" \
    "$BIN_DIR/leikwan-mptcp-client.py"
fi

echo "uninstalled $SERVICE_NAME"
