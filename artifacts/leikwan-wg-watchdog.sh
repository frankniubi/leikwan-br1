#!/usr/bin/env bash
# leikwan-wg-watchdog.sh - WireGuard health monitor
# Invoked by systemd timer every 60s. Checks all lw*/wg* interfaces for stale
# handshakes or fresh-handshake/frozen-rx one-way failures and restarts wg-quick
# if a peer exceeds the threshold.
set -euo pipefail

STALE_THRESHOLD_SECONDS=180
COOLDOWN_SECONDS=300
# BUG-4(c): require frozen rx to persist across THREE consecutive polls before
# we treat a fresh-handshake tunnel as one-way dead. Two polls (~120s) produced
# false restarts on idle-but-healthy peers; three polls (~180s) needs sustained
# evidence that inbound traffic has truly stopped while we are still sending.
RX_STALE_POLLS=3
# STATE_DIR holds per-interface poll state (cooldown + rx/tx transfer streaks).
# An env override lets the systemd unit (or tests) relocate it off /run.
STATE_DIR="${LEIKWAN_WG_WATCHDOG_STATE_DIR:-/run/leikwan-wg-watchdog}"

# C.7a restart-ledger circuit breaker (cross-language byte contract shared with
# slave_agent.py and cfwg_latency_watchdog.py). One append-only file per
# interface at <LEDGER_DIR>/<iface>.log; each restart appends a single line of
# "<int epoch> <source token>\n". Before restarting we count entries whose epoch
# is within the window; if that count has reached the max we SUPPRESS the
# restart (the breaker is open) and append nothing.
LEIKWAN_RESTART_LEDGER_DIR="${LEIKWAN_RESTART_LEDGER_DIR:-/run/leikwan/restart-ledger}"
LEIKWAN_RESTART_LEDGER_WINDOW="${LEIKWAN_RESTART_LEDGER_WINDOW:-600}"
LEIKWAN_RESTART_LEDGER_MAX="${LEIKWAN_RESTART_LEDGER_MAX:-3}"

# Source optional config override
if [[ -f /etc/leikwan/wg-watchdog.conf ]]; then
  # shellcheck disable=SC1091
  . /etc/leikwan/wg-watchdog.conf
fi

# Resolve effective breaker tunables, falling back to the documented defaults on
# any non-positive / non-numeric value (fail-safe parsing, never crash).
ledger_window=600
if [[ "${LEIKWAN_RESTART_LEDGER_WINDOW}" =~ ^[0-9]+$ ]] && (( LEIKWAN_RESTART_LEDGER_WINDOW > 0 )); then
  ledger_window="${LEIKWAN_RESTART_LEDGER_WINDOW}"
fi
ledger_max=3
if [[ "${LEIKWAN_RESTART_LEDGER_MAX}" =~ ^[0-9]+$ ]] && (( LEIKWAN_RESTART_LEDGER_MAX > 0 )); then
  ledger_max="${LEIKWAN_RESTART_LEDGER_MAX}"
fi
ledger_dir="${LEIKWAN_RESTART_LEDGER_DIR}"

# Count ledger entries for an interface whose epoch is within the breaker
# window. Reads fail-open: any missing/unreadable/corrupt ledger yields 0 so a
# real fault is never blocked by ledger problems.
ledger_recent_count() {
  local iface="$1" moment="$2"
  local ledger_file="${ledger_dir}/${iface}.log"
  local cutoff=$(( moment - ledger_window ))
  local count=0
  [[ -f "$ledger_file" ]] || { echo 0; return 0; }
  local epoch _rest
  while read -r epoch _rest; do
    [[ "$epoch" =~ ^[0-9]+$ ]] || continue
    if (( epoch >= cutoff )); then
      count=$(( count + 1 ))
    fi
  done < "$ledger_file" 2>/dev/null || { echo 0; return 0; }
  echo "$count"
}

# Append one ledger line for a performed restart. Tolerates an absent or
# unwritable directory (fail-open): the restart still happens, we just fail to
# record it. Never aborts the script.
ledger_append() {
  local iface="$1" token="$2" moment="$3"
  local ledger_file="${ledger_dir}/${iface}.log"
  ( mkdir -p "$ledger_dir" 2>/dev/null && chmod 0755 "$ledger_dir" 2>/dev/null
    printf '%s %s\n' "$moment" "$token" >> "$ledger_file" 2>/dev/null ) || true
}

mkdir -p "$STATE_DIR"

now=$(date +%s)

for iface in $(wg show interfaces 2>/dev/null); do
  # Only monitor leikwan-managed or wg-prefixed interfaces
  case "$iface" in
    lw*|wg*) ;;
    *) continue ;;
  esac

  # Check cooldown - skip if we restarted this interface recently
  cooldown_file="$STATE_DIR/$iface.last_restart"
  if [[ -f "$cooldown_file" ]]; then
    last_restart=$(cat "$cooldown_file" 2>/dev/null || echo 0)
    elapsed=$(( now - last_restart ))
    if (( elapsed < COOLDOWN_SECONDS )); then
      continue
    fi
  fi

  # BUG-4(a): PersistentKeepalive exclusion. A peer configured with
  # PersistentKeepalive>0 deliberately emits periodic keepalives, so on an idle
  # tunnel its tx keeps moving while rx legitimately stays flat. Treating frozen
  # rx as a one-way fault there causes needless restarts, so we collect the
  # per-peer keepalive setting and skip the frozen-rx gate for any peer that has
  # one. (The handshake-stale gate below still applies to every peer.)
  declare -A keepalive_by_peer=()
  while IFS=$'\t' read -r ka_peer ka_value; do
    [[ -z "${ka_peer:-}" ]] && continue
    keepalive_by_peer["$ka_peer"]="${ka_value:-off}"
  done < <(wg show "$iface" persistent-keepalive 2>/dev/null || true)

  # BUG-4(b): track BOTH rx and tx across polls. The frozen-rx gate must observe
  # that tx ADVANCED between two consecutive polls (current_tx > previous_tx) -
  # proof we are actively sending - rather than the weaker "cumulative tx > 0"
  # which stays true forever after a single byte and would fire on a long-idle
  # tunnel. We persist prev_tx_by_peer alongside prev_rx_by_peer.
  transfer_state_file="$STATE_DIR/$iface.transfer_state"
  declare -A prev_rx_by_peer=()
  declare -A prev_tx_by_peer=()
  declare -A prev_rx_streak_by_peer=()
  declare -A rx_by_peer=()
  declare -A tx_by_peer=()
  if [[ -f "$transfer_state_file" ]]; then
    while IFS=$'\t' read -r state_peer state_rx state_tx state_streak; do
      [[ -z "${state_peer:-}" ]] && continue
      prev_rx_by_peer["$state_peer"]="${state_rx:-0}"
      prev_tx_by_peer["$state_peer"]="${state_tx:-0}"
      prev_rx_streak_by_peer["$state_peer"]="${state_streak:-0}"
    done < "$transfer_state_file"
  fi
  while IFS=$'\t' read -r peer_pubkey rx_bytes tx_bytes; do
    [[ -z "${peer_pubkey:-}" ]] && continue
    rx_by_peer["$peer_pubkey"]="${rx_bytes:-0}"
    tx_by_peer["$peer_pubkey"]="${tx_bytes:-0}"
  done < <(wg show "$iface" transfer 2>/dev/null || true)
  transfer_tmp="$transfer_state_file.tmp"
  : > "$transfer_tmp"

  # Parse handshake timestamps for each peer on this interface.
  needs_restart=false
  stale_peer=""
  stale_reason=""
  while IFS=$'\t' read -r peer_pubkey handshake_epoch; do
    if [[ -z "$handshake_epoch" || "$handshake_epoch" == "0" ]]; then
      # Never had a handshake - interface may be freshly created, skip.
      continue
    fi
    age=$(( now - handshake_epoch ))
    current_rx="${rx_by_peer[$peer_pubkey]:-}"
    current_tx="${tx_by_peer[$peer_pubkey]:-0}"
    if [[ "$current_rx" =~ ^[0-9]+$ && "$current_tx" =~ ^[0-9]+$ ]]; then
      previous_rx="${prev_rx_by_peer[$peer_pubkey]:-}"
      previous_tx="${prev_tx_by_peer[$peer_pubkey]:-}"
      previous_streak="${prev_rx_streak_by_peer[$peer_pubkey]:-0}"
      # rx_streak counts consecutive polls where rx did NOT advance.
      rx_streak=0
      if [[ -n "$previous_rx" && "$current_rx" == "$previous_rx" ]]; then
        rx_streak=$(( previous_streak + 1 ))
      fi
      # tx_advanced is true only when tx strictly grew since the previous poll
      # (BUG-4(b)): proof we are still actively sending into the tunnel right now.
      tx_advanced=false
      if [[ "$previous_tx" =~ ^[0-9]+$ ]] && (( current_tx > previous_tx )); then
        tx_advanced=true
      fi
      # keepalive_off is true only when this peer has no PersistentKeepalive
      # (BUG-4(a)): the frozen-rx gate must not apply to keepalive peers.
      keepalive_setting="${keepalive_by_peer[$peer_pubkey]:-off}"
      keepalive_off=true
      if [[ "$keepalive_setting" =~ ^[0-9]+$ ]] && (( keepalive_setting > 0 )); then
        keepalive_off=false
      fi
      printf '%s\t%s\t%s\t%s\n' "$peer_pubkey" "$current_rx" "$current_tx" "$rx_streak" >> "$transfer_tmp"
      if [[ "$keepalive_off" == true && "$tx_advanced" == true ]] && (( age <= STALE_THRESHOLD_SECONDS && rx_streak >= RX_STALE_POLLS )); then
        needs_restart=true
        stale_peer="${peer_pubkey:0:8}...${peer_pubkey: -6}"
        stale_reason="fresh handshake but rx unchanged for ${rx_streak} polls while tx advancing"
        break
      fi
    fi
    if (( age > STALE_THRESHOLD_SECONDS )); then
      needs_restart=true
      stale_peer="${peer_pubkey:0:8}...${peer_pubkey: -6}"
      stale_reason="handshake stale (${age}s > ${STALE_THRESHOLD_SECONDS}s threshold)"
      break
    fi
  done < <(wg show "$iface" latest-handshakes 2>/dev/null)
  mv "$transfer_tmp" "$transfer_state_file" 2>/dev/null || true

  if $needs_restart; then
    # C.7a: consult the shared restart-ledger circuit breaker before restarting.
    # If too many restarts have already occurred within the window the breaker is
    # open: suppress this restart and record nothing.
    recent=$(ledger_recent_count "$iface" "$now")
    if (( recent >= ledger_max )); then
      echo "leikwan-wg-watchdog: WARN circuit breaker open for ${iface}: ${recent} restarts in ${ledger_window}s; backing off"
      continue
    fi
    echo "leikwan-wg-watchdog: restarted wg-quick@${iface} - peer ${stale_peer} ${stale_reason}"
    systemctl restart "wg-quick@${iface}.service" 2>/dev/null || true
    echo "$now" > "$cooldown_file"
    ledger_append "$iface" "handshake-watchdog" "$now"
  fi
done
