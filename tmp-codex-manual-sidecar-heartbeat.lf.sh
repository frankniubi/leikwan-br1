#!/usr/bin/env bash
set -euo pipefail
curl -k -sS -X POST 'https://v3.leikwanhost.com/api/sidecar/heartbeat' \
  -H 'Authorization: Bearer dfc1b1a986d578611616385321fe1387df1ea36ebd794c14' \
  -H 'Content-Type: application/json' \
  --data '{"status":"ok","version":"2026.05.13.1","usage_bytes_in":0,"usage_bytes_out":0,"supports_mptcp":true,"wireguard_public_key":"bBbi/7CsWMWrX/TrM/mRwTWceO3UoOIAL2S1BXLXCw4=","wireguard_listen_port":14969,"wireguard_fallback_tcp_port":24969}' \
| python3 -m json.tool | sed -n '1,260p'