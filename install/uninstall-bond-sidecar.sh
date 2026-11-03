#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="leikwan-bond-sidecar"
CONFIG_DIR="/etc/leikwan-sidecar"
BIN_DIR="/usr/local/bin"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    --config-dir) CONFIG_DIR="$2"; shift 2 ;;
    --bin-dir) BIN_DIR="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

AGENT_SERVICE_NAME="${SERVICE_NAME}-agent"
MPTCP_SERVICE_NAME="${SERVICE_NAME/bond-sidecar/mptcp-sidecar}"
POINT_SUFFIX="${SERVICE_NAME##*-}"
POINT_CONFIG_DIR="/etc/leikwan-sidecar-${POINT_SUFFIX}"
POINT_STATE_DIR="/var/lib/leikwan-sidecar-${POINT_SUFFIX}"
systemctl stop "$AGENT_SERVICE_NAME.service" 2>/dev/null || true
systemctl disable "$AGENT_SERVICE_NAME.service" 2>/dev/null || true
systemctl stop "$SERVICE_NAME.service" 2>/dev/null || true
systemctl disable "$SERVICE_NAME.service" 2>/dev/null || true
if [[ "$MPTCP_SERVICE_NAME" != "$SERVICE_NAME" ]]; then
  systemctl stop "$MPTCP_SERVICE_NAME.service" 2>/dev/null || true
  systemctl disable "$MPTCP_SERVICE_NAME.service" 2>/dev/null || true
fi
rm -f "/etc/systemd/system/$AGENT_SERVICE_NAME.service" "/etc/systemd/system/$SERVICE_NAME.service" "/etc/systemd/system/$MPTCP_SERVICE_NAME.service"
systemctl daemon-reload || true
rm -rf "$CONFIG_DIR"
rm -rf "$POINT_CONFIG_DIR" "$POINT_STATE_DIR"
rm -f "$BIN_DIR/leikwan-sidecar-agent.py" "$BIN_DIR/leikwan-mptcp-sidecar.py"

echo "uninstalled $SERVICE_NAME, $MPTCP_SERVICE_NAME and $AGENT_SERVICE_NAME"
