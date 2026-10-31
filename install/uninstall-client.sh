#!/usr/bin/env bash
set -euo pipefail

FORWARD_ID=""
SERVICE_NAME=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --forward-id) FORWARD_ID="$2"; shift 2 ;;
    --service-name) SERVICE_NAME="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$SERVICE_NAME" && -n "$FORWARD_ID" ]]; then
  SERVICE_NAME="leikwan-client-$FORWARD_ID"
fi
if [[ -z "$SERVICE_NAME" ]]; then
  echo "usage: uninstall-client.sh --forward-id <id> | --service-name <name>" >&2
  exit 1
fi

systemctl stop "$SERVICE_NAME.service" 2>/dev/null || true
systemctl disable "$SERVICE_NAME.service" 2>/dev/null || true
rm -f "/etc/systemd/system/$SERVICE_NAME.service"
systemctl daemon-reload || true

echo "uninstalled $SERVICE_NAME"
