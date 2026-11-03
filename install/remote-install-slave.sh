#!/usr/bin/env bash
set -euo pipefail

HOST=""
MASTER_URL=""
TOKEN=""
GROUP_KEY="default"
REGION="unknown"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --host) HOST="$2"; shift 2 ;;
    --master) MASTER_URL="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --group) GROUP_KEY="$2"; shift 2 ;;
    --region) REGION="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$HOST" || -z "$MASTER_URL" || -z "$TOKEN" ]]; then
  echo "usage: remote-install-slave.sh --host <host> --master <url> --token <token>" >&2
  exit 1
fi

ssh "$HOST" "curl -fsSL '$MASTER_URL/artifacts/install-slave.sh' | bash -s -- --master '$MASTER_URL' --token '$TOKEN' --group '$GROUP_KEY' --region '$REGION'"
