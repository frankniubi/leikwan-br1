#!/usr/bin/env bash
set -euo pipefail

# This runner is intentionally local-only. It never reads SSH configuration and
# never opens a connection outside its three RFC 1918 network namespaces.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_MANIFEST="${SCRIPT_DIR}/manifests/local-netns.json"
FORMAL_DURATION_SECONDS=60
RESERVED_PORT=20013

usage() {
  cat <<'EOF'
Usage:
  netns.sh setup --artifact-dir DIR --client-bin BIN --sidecar-bin BIN [--manifest FILE] [--dry-run]
  netns.sh run --case sessions64|tunnel32|combined --mode legacy|feedback_drr \
    --impairment none|delay1|delay5|delay10|loss01|loss05|stall500|reconnect|feedback-delay10 \
    --artifact-dir DIR [--duration-seconds 60] [--manifest FILE] [--dry-run]
  netns.sh teardown --artifact-dir DIR [--manifest FILE] [--dry-run]

The run action configures the bounded local topology and writes netns-run.json.
Traffic generation remains an explicit caller action; this script never treats
topology setup alone as performance evidence.
EOF
}

die() {
  printf 'error: %s\n' "$*" >&2
  exit 2
}

have_namespace() {
  ip netns list | awk '{print $1}' | grep -Fxq -- "$1"
}

require_root_tools() {
  [[ "${EUID}" -eq 0 ]] || die "network namespace actions require root"
  command -v ip >/dev/null || die "iproute2 is required"
  command -v tc >/dev/null || die "tc is required"
  command -v python3 >/dev/null || die "python3 is required"
}

load_manifest() {
  [[ -f "${MANIFEST}" ]] || die "manifest not found: ${MANIFEST}"
  mapfile -t TOPOLOGY_LINES < <(python3 - "${MANIFEST}" <<'PY'
import ipaddress
import json
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
marker = data["marker"]
namespaces = data["namespaces"]
if not marker.startswith("uot-qa-"):
    raise SystemExit("unsafe marker")
if data.get("hop_order") != ["client", "slave", "sidecar"]:
    raise SystemExit("unsafe hop order")
if 20013 not in data.get("reserved_ports", []):
    raise SystemExit("reserved port 20013 missing")
for name in namespaces.values():
    if marker not in name:
        raise SystemExit("unsafe namespace name")

print(marker)
print(namespaces["client"])
print(namespaces["slave"])
print(namespaces["sidecar"])
for leg in data["legs"]:
    port = int(leg["ingress_port"])
    if not 7000 <= port <= 9000 or port == 20013:
        raise SystemExit("unsafe ingress port")
    client = ipaddress.ip_interface(leg["client_addr"])
    slave = ipaddress.ip_interface(leg["slave_addr"])
    slave_sidecar = ipaddress.ip_interface(leg["slave_sidecar_addr"])
    sidecar = ipaddress.ip_interface(leg["sidecar_addr"])
    values = (
        int(leg["id"]), client, slave, slave_sidecar, sidecar, port,
        client.network, sidecar.network,
    )
    print("|".join(map(str, values)))
PY
  )
  [[ "${#TOPOLOGY_LINES[@]}" -eq 9 ]] || die "manifest must contain exactly five legs"
  MARKER="${TOPOLOGY_LINES[0]}"
  CLIENT_NS="${TOPOLOGY_LINES[1]}"
  SLAVE_NS="${TOPOLOGY_LINES[2]}"
  SIDECAR_NS="${TOPOLOGY_LINES[3]}"
  LEG_ROWS=("${TOPOLOGY_LINES[@]:4}")
  for namespace in "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"; do
    [[ "${namespace}" == *"${MARKER}"* ]] || die "refusing unsafe namespace: ${namespace}"
  done
}

device_names() {
  local id="$1"
  CLIENT_DEV="uq08c${id}"
  SLAVE_CLIENT_DEV="uq08s${id}"
  SLAVE_SIDECAR_DEV="uq08x${id}"
  SIDECAR_DEV="uq08d${id}"
}

delete_marked_root_device() {
  local device="$1"
  if ! ip link show dev "${device}" >/dev/null 2>&1; then
    return
  fi
  local details
  details="$(ip -d link show dev "${device}")"
  [[ "${details}" == *"${MARKER}"* ]] || die "refusing to delete unmarked root device: ${device}"
  ip link delete dev "${device}"
}

teardown_topology() {
  printf 'teardown marker=%s namespaces=%s,%s,%s\n' \
    "${MARKER}" "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"
  if [[ "${DRY_RUN}" == "1" ]]; then
    return
  fi
  for namespace in "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"; do
    [[ "${namespace}" == *"${MARKER}"* ]] || die "refusing to delete unmarked namespace: ${namespace}"
    if have_namespace "${namespace}"; then
      ip netns pids "${namespace}" | xargs -r kill >/dev/null 2>&1 || true
      ip netns delete "${namespace}"
    fi
  done
  for row in "${LEG_ROWS[@]}"; do
    IFS='|' read -r id _rest <<<"${row}"
    device_names "${id}"
    delete_marked_root_device "${CLIENT_DEV}"
    delete_marked_root_device "${SLAVE_CLIENT_DEV}"
    delete_marked_root_device "${SLAVE_SIDECAR_DEV}"
    delete_marked_root_device "${SIDECAR_DEV}"
  done
}

configure_forwarding() {
  ip netns exec "${SLAVE_NS}" sysctl -q -w net.ipv4.ip_forward=1 >/dev/null
  if ip netns exec "${SLAVE_NS}" sh -c 'command -v iptables >/dev/null'; then
    ip netns exec "${SLAVE_NS}" iptables -P FORWARD ACCEPT
    for row in "${LEG_ROWS[@]}"; do
      IFS='|' read -r _id _client_addr slave_addr _slave_sidecar_addr sidecar_addr port _client_net _sidecar_net <<<"${row}"
      local slave_ip="${slave_addr%/*}"
      local sidecar_ip="${sidecar_addr%/*}"
      if ! ip netns exec "${SLAVE_NS}" iptables -t nat -C PREROUTING \
        -d "${slave_ip}" -p tcp --dport "${port}" -j DNAT --to-destination "${sidecar_ip}:${port}" 2>/dev/null; then
        ip netns exec "${SLAVE_NS}" iptables -t nat -A PREROUTING \
          -d "${slave_ip}" -p tcp --dport "${port}" -j DNAT --to-destination "${sidecar_ip}:${port}"
      fi
    done
  fi
}

topology_complete() {
  for namespace in "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"; do
    have_namespace "${namespace}" || return 1
  done
  for row in "${LEG_ROWS[@]}"; do
    IFS='|' read -r id _rest <<<"${row}"
    device_names "${id}"
    ip -n "${CLIENT_NS}" link show dev "${CLIENT_DEV}" >/dev/null 2>&1 || return 1
    ip -n "${SLAVE_NS}" link show dev "${SLAVE_CLIENT_DEV}" >/dev/null 2>&1 || return 1
    ip -n "${SLAVE_NS}" link show dev "${SLAVE_SIDECAR_DEV}" >/dev/null 2>&1 || return 1
    ip -n "${SIDECAR_NS}" link show dev "${SIDECAR_DEV}" >/dev/null 2>&1 || return 1
  done
  return 0
}

setup_topology() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    printf 'setup marker=%s hop=client->slave->sidecar legs=5 ingress=8801-8805 reserved=%s\n' \
      "${MARKER}" "${RESERVED_PORT}"
    return
  fi

  local existing=0
  for namespace in "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"; do
    have_namespace "${namespace}" && existing=$((existing + 1))
  done
  if [[ "${existing}" -gt 0 ]] && ! topology_complete; then
    teardown_topology
  fi
  for namespace in "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"; do
    have_namespace "${namespace}" || ip netns add "${namespace}"
    ip -n "${namespace}" link set lo up
  done

  for row in "${LEG_ROWS[@]}"; do
    IFS='|' read -r id client_addr slave_addr slave_sidecar_addr sidecar_addr _port client_net sidecar_net <<<"${row}"
    device_names "${id}"
    if ! ip -n "${CLIENT_NS}" link show dev "${CLIENT_DEV}" >/dev/null 2>&1; then
      delete_marked_root_device "${CLIENT_DEV}"
      delete_marked_root_device "${SLAVE_CLIENT_DEV}"
      ip link add "${CLIENT_DEV}" type veth peer name "${SLAVE_CLIENT_DEV}"
      ip link set dev "${CLIENT_DEV}" alias "${MARKER}-leg${id}-client"
      ip link set dev "${SLAVE_CLIENT_DEV}" alias "${MARKER}-leg${id}-slave-in"
      ip link set "${CLIENT_DEV}" netns "${CLIENT_NS}"
      ip link set "${SLAVE_CLIENT_DEV}" netns "${SLAVE_NS}"
    fi
    if ! ip -n "${SLAVE_NS}" link show dev "${SLAVE_SIDECAR_DEV}" >/dev/null 2>&1; then
      delete_marked_root_device "${SLAVE_SIDECAR_DEV}"
      delete_marked_root_device "${SIDECAR_DEV}"
      ip link add "${SLAVE_SIDECAR_DEV}" type veth peer name "${SIDECAR_DEV}"
      ip link set dev "${SLAVE_SIDECAR_DEV}" alias "${MARKER}-leg${id}-slave-out"
      ip link set dev "${SIDECAR_DEV}" alias "${MARKER}-leg${id}-sidecar"
      ip link set "${SLAVE_SIDECAR_DEV}" netns "${SLAVE_NS}"
      ip link set "${SIDECAR_DEV}" netns "${SIDECAR_NS}"
    fi

    ip -n "${CLIENT_NS}" address replace "${client_addr}" dev "${CLIENT_DEV}"
    ip -n "${SLAVE_NS}" address replace "${slave_addr}" dev "${SLAVE_CLIENT_DEV}"
    ip -n "${SLAVE_NS}" address replace "${slave_sidecar_addr}" dev "${SLAVE_SIDECAR_DEV}"
    ip -n "${SIDECAR_NS}" address replace "${sidecar_addr}" dev "${SIDECAR_DEV}"
    ip -n "${CLIENT_NS}" link set "${CLIENT_DEV}" up
    ip -n "${SLAVE_NS}" link set "${SLAVE_CLIENT_DEV}" up
    ip -n "${SLAVE_NS}" link set "${SLAVE_SIDECAR_DEV}" up
    ip -n "${SIDECAR_NS}" link set "${SIDECAR_DEV}" up

    ip -n "${CLIENT_NS}" route replace "${sidecar_net}" via "${slave_addr%/*}" dev "${CLIENT_DEV}"
    ip -n "${SIDECAR_NS}" route replace "${client_net}" via "${slave_sidecar_addr%/*}" dev "${SIDECAR_DEV}"
  done
  configure_forwarding
}

clear_impairments() {
  for row in "${LEG_ROWS[@]}"; do
    IFS='|' read -r id _rest <<<"${row}"
    device_names "${id}"
    ip netns exec "${SLAVE_NS}" tc qdisc del dev "${SLAVE_SIDECAR_DEV}" root 2>/dev/null || true
    ip netns exec "${SIDECAR_NS}" tc qdisc del dev "${SIDECAR_DEV}" root 2>/dev/null || true
  done
}

apply_impairment() {
  clear_impairments
  device_names 1
  case "${IMPAIRMENT}" in
    none) ;;
    delay1) ip netns exec "${SLAVE_NS}" tc qdisc replace dev "${SLAVE_SIDECAR_DEV}" root netem delay 1ms ;;
    delay5) ip netns exec "${SLAVE_NS}" tc qdisc replace dev "${SLAVE_SIDECAR_DEV}" root netem delay 5ms ;;
    delay10) ip netns exec "${SLAVE_NS}" tc qdisc replace dev "${SLAVE_SIDECAR_DEV}" root netem delay 10ms ;;
    loss01) ip netns exec "${SLAVE_NS}" tc qdisc replace dev "${SLAVE_SIDECAR_DEV}" root netem loss 0.1% ;;
    loss05) ip netns exec "${SLAVE_NS}" tc qdisc replace dev "${SLAVE_SIDECAR_DEV}" root netem loss 0.5% ;;
    stall500) ip netns exec "${SLAVE_NS}" tc qdisc replace dev "${SLAVE_SIDECAR_DEV}" root netem delay 500ms ;;
    reconnect)
      ip -n "${SLAVE_NS}" link set "${SLAVE_SIDECAR_DEV}" down
      sleep 0.5
      ip -n "${SLAVE_NS}" link set "${SLAVE_SIDECAR_DEV}" up
      ;;
    feedback-delay10)
      ip netns exec "${SIDECAR_NS}" tc qdisc replace dev "${SIDECAR_DEV}" root netem delay 10ms
      ;;
    *) die "unsupported impairment: ${IMPAIRMENT}" ;;
  esac
}

write_setup_state() {
  python3 - "${ARTIFACT_DIR}/netns-state.json" "${MARKER}" "${CLIENT_NS}" "${SLAVE_NS}" \
    "${SIDECAR_NS}" "${CLIENT_BIN}" "${SIDECAR_BIN}" <<'PY'
import json
import pathlib
import sys

output, marker, client, slave, sidecar, client_bin, sidecar_bin = sys.argv[1:]
value = {
    "marker": marker,
    "hop_order": ["client", "slave", "sidecar"],
    "namespaces": {"client": client, "slave": slave, "sidecar": sidecar},
    "reserved_ports_untouched": [20013],
    "client_binary": str(pathlib.Path(client_bin).resolve()),
    "sidecar_binary": str(pathlib.Path(sidecar_bin).resolve()),
}
pathlib.Path(output).write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

write_run_context() {
  python3 - "${ARTIFACT_DIR}/netns-run.json" "${CASE}" "${MODE}" "${IMPAIRMENT}" \
    "${DURATION_SECONDS}" "${MARKER}" <<'PY'
import json
import pathlib
import sys

output, case, mode, impairment, duration, marker = sys.argv[1:]
value = {
    "case": case,
    "mode": mode,
    "impairment": impairment,
    "duration_seconds": int(duration),
    "marker": marker,
    "hop_order": ["client", "slave", "sidecar"],
    "reserved_ports_untouched": [20013],
    "topology_configured": True,
    "traffic_executed": False,
    "formal_evidence": False,
}
pathlib.Path(output).write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

ACTION="${1:-}"
[[ -n "${ACTION}" ]] || { usage; exit 2; }
shift || true

MANIFEST="${DEFAULT_MANIFEST}"
ARTIFACT_DIR=""
CLIENT_BIN=""
SIDECAR_BIN=""
CASE=""
MODE=""
IMPAIRMENT="none"
DURATION_SECONDS="${FORMAL_DURATION_SECONDS}"
DRY_RUN=0
SETUP_IN_PROGRESS=0

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --manifest) MANIFEST="${2:?missing --manifest value}"; shift 2 ;;
    --artifact-dir) ARTIFACT_DIR="${2:?missing --artifact-dir value}"; shift 2 ;;
    --client-bin) CLIENT_BIN="${2:?missing --client-bin value}"; shift 2 ;;
    --sidecar-bin) SIDECAR_BIN="${2:?missing --sidecar-bin value}"; shift 2 ;;
    --case) CASE="${2:?missing --case value}"; shift 2 ;;
    --mode) MODE="${2:?missing --mode value}"; shift 2 ;;
    --impairment) IMPAIRMENT="${2:?missing --impairment value}"; shift 2 ;;
    --duration-seconds) DURATION_SECONDS="${2:?missing --duration-seconds value}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

case "${ACTION}" in
  setup|run|teardown) ;;
  *) usage; die "unsupported action: ${ACTION}" ;;
esac
[[ -n "${ARTIFACT_DIR}" ]] || die "--artifact-dir is required"
[[ "${DURATION_SECONDS}" == "${FORMAL_DURATION_SECONDS}" ]] || die "duration must be 60 seconds"

load_manifest
if [[ "${DRY_RUN}" != "1" ]]; then
  require_root_tools
  mkdir -p -- "${ARTIFACT_DIR}"
fi

cleanup_partial_setup() {
  local status="$?"
  trap - ERR
  if [[ "${SETUP_IN_PROGRESS}" == "1" && "${DRY_RUN}" != "1" ]]; then
    teardown_topology || true
  fi
  exit "${status}"
}
trap cleanup_partial_setup ERR

case "${ACTION}" in
  setup)
    [[ -n "${CLIENT_BIN}" && -f "${CLIENT_BIN}" ]] || die "--client-bin must name a file"
    [[ -n "${SIDECAR_BIN}" && -f "${SIDECAR_BIN}" ]] || die "--sidecar-bin must name a file"
    SETUP_IN_PROGRESS=1
    setup_topology
    if [[ "${DRY_RUN}" != "1" ]]; then
      write_setup_state
    fi
    SETUP_IN_PROGRESS=0
    ;;
  run)
    case "${CASE}" in sessions64|tunnel32|combined) ;; *) die "unsupported case: ${CASE}" ;; esac
    case "${MODE}" in legacy|feedback_drr) ;; *) die "unsupported mode: ${MODE}" ;; esac
    case "${IMPAIRMENT}" in none|delay1|delay5|delay10|loss01|loss05|stall500|reconnect|feedback-delay10) ;;
      *) die "unsupported impairment: ${IMPAIRMENT}" ;;
    esac
    if [[ "${DRY_RUN}" == "1" ]]; then
      printf 'run case=%s mode=%s impairment=%s duration=%s topology=client->slave->sidecar reserved=%s\n' \
        "${CASE}" "${MODE}" "${IMPAIRMENT}" "${DURATION_SECONDS}" "${RESERVED_PORT}"
      exit 0
    fi
    for namespace in "${CLIENT_NS}" "${SLAVE_NS}" "${SIDECAR_NS}"; do
      have_namespace "${namespace}" || die "namespace is not set up: ${namespace}"
    done
    apply_impairment
    write_run_context
    ;;
  teardown)
    teardown_topology
    ;;
esac
