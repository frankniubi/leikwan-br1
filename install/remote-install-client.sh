#!/usr/bin/env bash
set -euo pipefail

HOST=""
MASTER_URL=""
FORWARD_ID=""
TOKEN=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --host) HOST="$2"; shift 2 ;;
    --master) MASTER_URL="$2"; shift 2 ;;
    --forward-id) FORWARD_ID="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$HOST" || -z "$MASTER_URL" || -z "$FORWARD_ID" || -z "$TOKEN" ]]; then
  echo "usage: remote-install-client.sh --host <host> --master <url> --forward-id <id> --token <token>" >&2
  exit 1
fi

ssh "$HOST" "curl -fsSL '$MASTER_URL/artifacts/install-client.sh' | bash -s -- --master '$MASTER_URL' --forward-id '$FORWARD_ID' --token '$TOKEN'"
