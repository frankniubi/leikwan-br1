#!/bin/bash
# End-to-end test of gateway mode (native outbound) in network namespaces.
#
#   lkgw-c (LAN client)  --lan0-->  lkgw-r (router)  --wan0-->  lkgw-u (upstream)
#   192.168.77.2                    leikwand + ultra-client       fake relay on
#                                   + OpenWrt's sing-box          198.51.100.10:19000
#                                   + mock panel (loopback)       (behind a gateway)
#
# What it proves, with the real binaries:
#   - the native lease request is accepted by a panel that checks it the way
#     ClientAgentController::resolveUltraLease does (ticket, nonce, digest,
#     scope, route policy), for the restricted and the unrestricted route;
#   - the relay starts, takes the batch and opens 127.0.0.1:2080;
#   - sing-box runs the generated config, captures forwarded LAN traffic and
#     hands it to the relay: a LAN connection to an address that does not
#     exist upstream is answered by the echo server behind the relay;
#   - the relay's own connection escapes the TUN only because of the bypass
#     rule (removing it breaks new connections, restoring it fixes them);
#   - a lease renewal reaches the running relay without restarting it or
#     sing-box;
#   - revoking native outbound stops capture and removes every rule.
#
# Requires root (netns, TUN, ip rule), python3 and a sing-box launcher.
# Usage: run_gateway_e2e.sh <leikwand> <ultra-client> <sing-box> [scope] [policy]
set -uo pipefail

LEIKWAND=$(readlink -f "${1:?path to a linux leikwand}")
ULTRA=$(readlink -f "${2:?path to a linux ultra-client}")
SINGBOX=$(readlink -f "${3:?path to a sing-box binary or launcher}")
SCOPE="${4:-auto}"
POLICY="${5:-auto}"
[ "$(id -u)" = "0" ] || { echo "run as root (network namespaces, TUN, ip rule)" >&2; exit 2; }

HERE=$(cd "$(dirname "$0")" && pwd)
RULESETS=$(cd "$HERE/../../../openwrt/leikwand/files/rule-set" && pwd)
R=lkgw-r; C=lkgw-c; U=lkgw-u
ROOT=$(mktemp -d /tmp/lkgw-XXXXXX)
STATE="$ROOT/panel-state"; DATA="$ROOT/data"; RUN="$ROOT/run"
mkdir -p "$STATE" "$DATA" "$RUN"
ART=/tmp/lkgw-artifacts
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
step() { printf '\n== %s ==\n' "$1"; }
inr()  { ip netns exec "$R" "$@"; }
wait_for() { local deadline=$(( $(date +%s) + $1 )); shift
	while [ "$(date +%s)" -lt "$deadline" ]; do "$@" >/dev/null 2>&1 && return 0; sleep 0.5; done; return 1; }

cleanup() {
	rm -rf "$ART"; mkdir -p "$ART"
	cp "$ROOT"/*.log "$ART/" 2>/dev/null
	cp "$RUN/gateway/sing-box.json" "$ART/" 2>/dev/null
	cp "$STATE/native_lease_requests.jsonl" "$ART/" 2>/dev/null
	for ns in $R $C $U; do
		for pid in $(ip netns pids "$ns" 2>/dev/null); do kill "$pid" 2>/dev/null; done
	done
	sleep 1
	for ns in $R $C $U; do
		for pid in $(ip netns pids "$ns" 2>/dev/null); do kill -9 "$pid" 2>/dev/null; done
		ip netns del "$ns" 2>/dev/null
	done
}
trap cleanup EXIT

# lan_echo <ip> <port> <marker>: connect from the LAN client, print the reply.
lan_echo() { ip netns exec "$C" python3 -c "
import socket,sys
try:
    s=socket.create_connection((sys.argv[1], int(sys.argv[2])), timeout=6)
    s.sendall(sys.argv[3].encode()); s.settimeout(6)
    print(s.recv(4096).decode(errors='replace'))
except Exception as e:
    print('ERR', e)" "$@"; }
# lan_ok <ip> <port> <marker>: the fake relay does not decode the Ultra
# stream, so like run_integration.sh only the echo server prefix is checked.
lan_ok() { case "$(lan_echo "$@")" in echo:*) return 0;; *) return 1;; esac; }
ctl() { inr env LEIKWAN_UCI_PATH="$ROOT/leikwan.uci" "$LEIKWAND" ctl "$@"; }
gw()  { ctl status | python3 -c "import json,sys; g=json.load(sys.stdin)['data'].get('gateway') or {}; print($1)"; }
pid_of() { for e in $(ip netns pids "$R"); do [ "$(readlink -f /proc/$e/exe 2>/dev/null)" = "$1" ] && echo "$e"; done | head -1; }
# sing-box runs through a launcher here, so it is found by its command line.
sb_pid() { for e in $(ip netns pids "$R"); do tr '\0' ' ' < /proc/$e/cmdline 2>/dev/null | grep -q "sing-box.* run -c" && echo "$e"; done | head -1; }

step "building the namespaces"
for ns in $R $C $U; do ip netns del "$ns" 2>/dev/null; ip netns add "$ns"; ip -n "$ns" link set lo up; done
ip link add lan0 netns $R type veth peer name c0 netns $C
ip link add wan0 netns $R type veth peer name u0 netns $U
ip -n $R addr add 192.168.77.1/24 dev lan0; ip -n $R link set lan0 up
ip -n $R addr add 10.99.0.1/24 dev wan0;    ip -n $R link set wan0 up
ip -n $R route add default via 10.99.0.2
inr sysctl -qw net.ipv4.ip_forward=1
inr sysctl -qw net.ipv4.conf.all.rp_filter=0
ip -n $C addr add 192.168.77.2/24 dev c0; ip -n $C link set c0 up
ip -n $C route add default via 192.168.77.1
ip -n $U addr add 10.99.0.2/24 dev u0; ip -n $U link set u0 up
ip -n $U addr add 198.51.100.10/32 dev lo
ip -n $U route add 192.168.77.0/24 via 10.99.0.1
ok "router, LAN client and upstream namespaces are up"

step "starting the upstream relay and the panel"
ip netns exec $U python3 "$HERE/echo_server.py" 19080 >"$ROOT/echo.log" 2>&1 &
ip netns exec $U env FAKE_RELAY_HOST=198.51.100.10 python3 "$HERE/fake_relay.py" 19000 19080 >"$ROOT/relay.log" 2>&1 &
echo 1 > "$STATE/native"
echo 198.51.100.10 > "$STATE/relay_host"
echo 19000 > "$STATE/relay_port"
echo 100 > "$STATE/native_ttl"
echo 0 > "$STATE/forward_count"
inr python3 "$HERE/mock_panel.py" "$STATE" 18080 >"$ROOT/panel.log" 2>&1 &
wait_for 10 test -f "$STATE/ready" && ok "mock panel ready" || bad "mock panel did not start"
# Without the gateway the LAN cannot reach the test destination at all.
[ "$(lan_echo 203.0.113.80 7777 baseline | cut -c1-3)" = "ERR" ] && ok "baseline: 203.0.113.80 is unreachable without the gateway" || bad "baseline destination is reachable; the test proves nothing"

step "starting leikwand in gateway mode (scope=$SCOPE policy=$POLICY)"
cat > "$ROOT/leikwan.uci" <<EOF
config leikwan 'main'
	option enabled '1'
	option panel_url 'http://127.0.0.1:18080'
	option fallback_panel_url 'http://127.0.0.1:18080'
	option panel_egress_interface 'none'
	option mode 'gateway'
	option poll_interval '5'
	option heartbeat_interval '5'
	option health_interval '3'
	option restart_cooldown '5'
	option lease_renew_lead '90'
	option log_level 'debug'
	option data_dir '$DATA'
	option run_dir '$RUN'
	option runtime_bin '$ULTRA'
	option gateway_enabled '1'
	option gateway_route_scope '$SCOPE'
	option gateway_route_policy '$POLICY'
	option gateway_singbox_bin '$SINGBOX'
	option gateway_rule_set_dir '$RULESETS'
EOF
python3 - "$DATA" <<'PY'
import json, os, sys
os.makedirs(sys.argv[1], exist_ok=True)
json.dump({"agent_token": "it-agent-token", "device_id": "openwrt-gateway-e2e", "device_name": "Gateway E2E",
           "client_id": 42, "client_name": "Gateway E2E", "master_url": "http://127.0.0.1:18080",
           "enrolled_at": "2020-01-01T00:00:00Z", "enroll_method": "integration"},
          open(os.path.join(sys.argv[1], "secret.json"), "w"))
PY
chmod 600 "$DATA/secret.json"
inr env LEIKWAN_UCI_PATH="$ROOT/leikwan.uci" "$LEIKWAND" run >"$ROOT/daemon.log" 2>&1 &

if wait_for 60 sh -c "ip netns exec $R ip link show lkwtun0"; then ok "sing-box brought up lkwtun0"; else bad "lkwtun0 never appeared"; fi
wait_for 20 test -s "$STATE/native_lease_requests.jsonl" && ok "native lease requested" || bad "no native lease request reached the panel"
[ -z "$(cat "$STATE/last_native_refusal" 2>/dev/null)" ] && ok "panel accepted the native lease request" \
	|| bad "panel refused the native lease: $(cat "$STATE/last_native_refusal")"
python3 - "$STATE/native_lease_requests.jsonl" <<'PY' && ok "request carries ticket, generation, nonce and the policy's digest" || bad "native lease request shape"
import json, sys
item = json.loads(open(sys.argv[1]).readline())
assert item["purpose"] == "native_outbound" and item["session_ticket"] == "it-ticket-50", item
assert len(item["policy_generation"]) == 64 and len(item.get("nonce", "")) == 32, item
assert item["route_scope"] in ("intl_only", "global", "cn_only") and item["route_policy"] in ("restricted", "unrestricted"), item
print("    scope=%s policy=%s" % (item["route_scope"], item["route_policy"]))
PY

step "LAN data path"
reply=$(lan_echo 203.0.113.80 7777 lan-through-native)
case "$reply" in echo:*) ok "LAN client -> TUN -> sing-box -> relay -> echo";; *) bad "LAN data path: '$reply'";; esac
inr ip rule show | grep -q "8970:.*to 198.51.100.10 lookup main" && ok "relay bypass rule installed ahead of sing-box" || bad "relay bypass rule missing: $(inr ip rule show | head -20)"
inr ip rule show | grep -q "^9000:" && ok "sing-box rules start at 9000" || bad "sing-box rules not at the pinned index"
GW_POLICY=$(gw "g.get('route_policy')")
if [ "$GW_POLICY" = "restricted" ]; then
	# Ports below 1000 never enter the relay under the restricted policy.
	[ "$(lan_echo 203.0.113.80 80 low-port | cut -c1-3)" = "ERR" ] && ok "restricted: port 80 stays off the relay" || bad "restricted: port 80 reached the relay"
else
	lan_ok 203.0.113.80 80 low-port && ok "unrestricted: port 80 is carried" || bad "unrestricted: port 80 not carried"
fi

step "the bypass rule is load-bearing"
inr ip rule del to 198.51.100.10/32 lookup main pref 8970
[ "$(lan_echo 203.0.113.80 7777 no-bypass | cut -c1-3)" = "ERR" ] && ok "without the rule the relay dials itself through the TUN and fails" || bad "traffic still flowed without the bypass rule"
inr ip rule add to 198.51.100.10/32 lookup main pref 8970
# The runtime quarantines the candidate that just failed, so recovery is
# bounded by its retry, not instant.
wait_for 45 lan_ok 203.0.113.80 7777 bypass-back && ok "restoring the rule restores the path" || bad "path did not recover"

step "lease renewal keeps the data plane"
RELAY_PID=$(pid_of "$ULTRA"); SB_PID=$(sb_pid)
[ -n "$RELAY_PID" ] && [ -n "$SB_PID" ] && ok "relay pid $RELAY_PID, sing-box pid $SB_PID" || bad "could not find the relay or sing-box process"
before=$(wc -l < "$STATE/native_lease_requests.jsonl")
wait_for 40 sh -c "[ \$(wc -l < '$STATE/native_lease_requests.jsonl') -gt $before ]" && ok "renewed before the 100 s lease expired" || bad "no renewal"
sleep 2
[ "$(pid_of "$ULTRA")" = "$RELAY_PID" ] && ok "relay kept running across the renewal" || bad "relay restarted on renewal"
[ -n "$SB_PID" ] && [ "$(sb_pid)" = "$SB_PID" ] && ok "sing-box kept running across the renewal" || bad "sing-box restarted on renewal"
lan_ok 203.0.113.80 7777 after-renewal && ok "LAN path still works after renewal" || bad "LAN path broke after renewal"
[ "$(gw "g.get('running')")" = "True" ] && ok "status reports the gateway running" || bad "status: $(gw 'g')"

step "usage"
native_bytes() { python3 - "$STATE/usage.jsonl" <<'PY'
import json, sys
total = 0
for line in open(sys.argv[1]):
    item = json.loads(line)
    if item.get("forward_id") == 50:
        total += item.get("bytes_in", 0) + item.get("bytes_out", 0)
sys.exit(0 if total > 0 else 1)
PY
}
wait_for 30 native_bytes && ok "the relay's traffic is reported as usage of native carrier #50" || bad "no native usage reached the panel heartbeat"

step "revocation"
echo 0 > "$STATE/native"
relay_gone() { [ -z "$(pid_of "$ULTRA")" ]; }
wait_for 40 relay_gone && ok "relay stopped after the panel withdrew native outbound" || bad "relay still running after revocation"
ip netns exec $R ip link show lkwtun0 >/dev/null 2>&1 && bad "sing-box kept capturing after revocation" || ok "capture stopped (lkwtun0 gone)"
inr ip rule show | grep -q "^8970:" && bad "bypass rules left behind" || ok "every bypass rule removed"
case "$(gw "g.get('last_error','')")" in *"not granted"*) ok "LuCI sees why";; *) bad "status error: $(gw "g.get('last_error')")";; esac

printf '\n%d passed, %d failed (logs in %s)\n' "$PASS" "$FAIL" "$ART"
[ "$FAIL" -eq 0 ]
