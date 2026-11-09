#!/usr/bin/env bash
set -euo pipefail

HOST=""
MASTER_URL=""
CLIENT_ID=""
AGENT_TOKEN=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --host) HOST="$2"; shift 2 ;;
    --master) MASTER_URL="$2"; shift 2 ;;
    --client-id) CLIENT_ID="$2"; shift 2 ;;
    --agent-token) AGENT_TOKEN="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$HOST" || -z "$MASTER_URL" || -z "$CLIENT_ID" || -z "$AGENT_TOKEN" ]]; then
  echo "usage: remote-install-client-agent.sh --host <host> --master <url> --client-id <id> --agent-token <token>" >&2
  exit 1
fi

ssh "$HOST" "curl -fsSL '$MASTER_URL/artifacts/install-client-agent.sh' | bash -s -- --master '$MASTER_URL' --client-id '$CLIENT_ID' --agent-token '$AGENT_TOKEN'"
