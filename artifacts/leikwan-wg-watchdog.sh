#!/usr/bin/env bash
# leikwan-wg-watchdog.sh — WireGuard health monitor
# Invoked by systemd timer every 60s. Checks all lw-*/wg* interfaces for stale
# handshakes and restarts wg-quick if a peer exceeds the threshold.
set -euo pipefail

STALE_THRESHOLD_SECONDS=180
COOLDOWN_SECONDS=300
STATE_DIR="/run/leikwan-wg-watchdog"

# Source optional config override
if [[ -f /etc/leikwan/wg-watchdog.conf ]]; then
  # shellcheck disable=SC1091
  . /etc/leikwan/wg-watchdog.conf
fi

mkdir -p "$STATE_DIR"

now=$(date +%s)

for iface in $(wg show interfaces 2>/dev/null); do
  # Only monitor leikwan-managed or wg-prefixed interfaces
  case "$iface" in
    lw-*|wg*|lwm*) ;;
    *) continue ;;
  esac

  # Check cooldown — skip if we restarted this interface recently
  cooldown_file="$STATE_DIR/$iface.last_restart"
  if [[ -f "$cooldown_file" ]]; then
    last_restart=$(cat "$cooldown_file" 2>/dev/null || echo 0)
    elapsed=$(( now - last_restart ))
    if (( elapsed < COOLDOWN_SECONDS )); then
      continue
    fi
  fi

  # Parse handshake timestamps for each peer on this interface
  needs_restart=false
  stale_peer=""
  stale_age=0
  while IFS=$'\t' read -r peer_pubkey handshake_epoch; do
    if [[ -z "$handshake_epoch" || "$handshake_epoch" == "0" ]]; then
      # Never had a handshake — interface may be freshly created, skip
      continue
    fi
    age=$(( now - handshake_epoch ))
    if (( age > STALE_THRESHOLD_SECONDS )); then
      needs_restart=true
      stale_peer="${peer_pubkey:0:8}...${peer_pubkey: -6}"
      stale_age=$age
      break
    fi
  done < <(wg show "$iface" latest-handshakes 2>/dev/null)

  if $needs_restart; then
    echo "leikwan-wg-watchdog: restarted wg-quick@${iface} — peer ${stale_peer} handshake stale (${stale_age}s > ${STALE_THRESHOLD_SECONDS}s threshold)"
    systemctl restart "wg-quick@${iface}.service" 2>/dev/null || true
    echo "$now" > "$cooldown_file"
  fi
done
