#!/usr/bin/env bash
set -euo pipefail

CONFIG_DIR="/etc/leikwan-slave"
BIN_DIR="/usr/local/bin"
SERVICE_NAME="leikwan-slave-agent"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

systemctl stop "$SERVICE_NAME.service" 2>/dev/null || true
systemctl disable "$SERVICE_NAME.service" 2>/dev/null || true
rm -f "/etc/systemd/system/$SERVICE_NAME.service"
systemctl daemon-reload || true
rm -rf "$CONFIG_DIR"
rm -f "$BIN_DIR/leikwan-slave-agent.py"
echo "uninstalled $SERVICE_NAME"
