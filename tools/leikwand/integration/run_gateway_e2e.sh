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
#     sing-box, and so does a Panel refresh, at most once per 20 s;
#   - revoking native outbound stops capture and removes every rule.
#
# The case argument picks the configuration:
#   full        the whole LAN through the primary carrier (default; 1.1.x)
#   cidr        gateway_capture=cidr, the PVE layout: only the listed
#               prefixes enter the TUN (the packaged SHIXP list plus the test
#               prefix), everything else - DNS included - stays direct
#   cidr-split  cidr capture plus a 'config split' carrier: the capture is the
#               union of both lists, each list leaves over its own carrier
#   split       the 10.3.32.1 router layout with 1.1.x keys only: full capture
#               over the primary carrier, the legacy gateway_split_* carrier
#               for the packaged SHIXP list (plus the test prefix) on 2081; a
#               split refresh leaves its outbound and rule in sing-box
#   splits      full capture with two split carriers (the legacy
#               gateway_split_* keys and a 'config split' section) whose lists
#               overlap: first match wins; per-carrier usage and heartbeat,
#               a split refresh that restarts nothing, and a Panel stop of one
#               split that leaves the others running
#
# Every stream the fake relay carries is logged as "<user> <destination>" and
# each carrier authenticates with its own user, so the test sees which carrier
# carried which LAN connection.
#
# Requires root (netns, TUN, ip rule), python3 and a sing-box launcher.
# Usage: run_gateway_e2e.sh <leikwand> <ultra-client> <sing-box> [scope] [policy] [case]
set -uo pipefail

LEIKWAND=$(readlink -f "${1:?path to a linux leikwand}")
ULTRA=$(readlink -f "${2:?path to a linux ultra-client}")
SINGBOX=$(readlink -f "${3:?path to a sing-box binary or launcher}")
SCOPE="${4:-auto}"
POLICY="${5:-auto}"
CASE="${6:-full}"
case "$CASE" in full|cidr|cidr-split|split|splits) ;; *) echo "unknown case '$CASE' (full|cidr|cidr-split|split|splits)" >&2; exit 2;; esac
[ "$(id -u)" = "0" ] || { echo "run as root (network namespaces, TUN, ip rule)" >&2; exit 2; }

HERE=$(cd "$(dirname "$0")" && pwd)
RULESETS=$(cd "$HERE/../../../openwrt/leikwand/files/rule-set" && pwd)
SHIXP_LIST=$(cd "$HERE/../../../openwrt/leikwand/files/split" && pwd)/shixp-v4.txt
R=lkgw-r; C=lkgw-c; U=lkgw-u
ROOT=$(mktemp -d /tmp/lkgw-XXXXXX)
STATE="$ROOT/panel-state"; DATA="$ROOT/data"; RUN="$ROOT/run"
STREAMS="$ROOT/relay-streams.log"
mkdir -p "$STATE" "$DATA" "$RUN"
ART=/tmp/lkgw-artifacts-$CASE
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
step() { printf '\n== %s ==\n' "$1"; }
inr()  { ip netns exec "$R" "$@"; }
wait_for() { local deadline=$(( $(date +%s) + $1 )); shift
	while [ "$(date +%s)" -lt "$deadline" ]; do "$@" >/dev/null 2>&1 && return 0; sleep 0.5; done; return 1; }

cleanup() {
	rm -rf "$ART"; mkdir -p "$ART"
	cp "$ROOT"/*.log "$ROOT/leikwan.uci" "$ART/" 2>/dev/null
	cp "$RUN/gateway/sing-box.json" "$ART/" 2>/dev/null
	cp "$ROOT"/runtime-*.json "$ART/" 2>/dev/null
	cp "$STATE/native_lease_requests.jsonl" "$STATE/usage.jsonl" "$STATE/last_heartbeat.json" "$ART/" 2>/dev/null
	for ns in $R $C $U; do
		for pid in $(ip netns pids "$ns" 2>/dev/null); do kill "$pid" 2>/dev/null; done
	done
	sleep 1
	for ns in $R $C $U; do
		for pid in $(ip netns pids "$ns" 2>/dev/null); do kill -9 "$pid" 2>/dev/null; done
		ip netns del "$ns" 2>/dev/null
	done
	rm -rf "$ROOT"
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
stream_count() { if [ -f "$STREAMS" ]; then wc -l < "$STREAMS"; else echo 0; fi; }
# carrier_of <ip> <port> <marker>: connect from the LAN and print the relay
# user that carried the stream, "direct" when it was answered without the
# relay, or "error: ..." when it was not answered at all.
carrier_of() {
	local before reply user
	before=$(stream_count)
	reply=$(lan_echo "$@")
	case "$reply" in echo:*) ;; *) echo "error: $reply"; return;; esac
	sleep 0.3
	user=$(tail -n +"$((before + 1))" "$STREAMS" 2>/dev/null | awk -v d="$1:$2" '$2 == d { u = $1 } END { print u }')
	echo "${user:-direct}"
}
# expect_carrier <label> <want> <ip> <port> <marker>: retries briefly, because
# the runtime quarantines a candidate after a failed probe.
expect_carrier() {
	local label="$1" want="$2" got="" deadline=$(( $(date +%s) + 30 ))
	shift 2
	while :; do
		got=$(carrier_of "$@")
		[ "$got" = "$want" ] && break
		[ "$(date +%s)" -ge "$deadline" ] && break
		sleep 1
	done
	[ "$got" = "$want" ] && ok "$label ($1:$2 -> $want)" || bad "$label: $1:$2 went to '$got', want '$want'"
}
ctl() { inr env LEIKWAN_UCI_PATH="$ROOT/leikwan.uci" "$LEIKWAND" ctl "$@"; }
gw()  { ctl status | python3 -c "import json,sys; g=json.load(sys.stdin)['data'].get('gateway') or {}; print($1)"; }
# slots <python expr over s, the slot list of 'leikwand ctl gateway'>
slots() { ctl gateway | python3 -c "import json,sys; s=json.load(sys.stdin)['data'].get('slots') or []; print($1)"; }
slot_state() { slots "s[$1]['state'] if len(s) > $1 else 'missing'"; }
pid_of() { for e in $(ip netns pids "$R"); do [ "$(readlink -f /proc/$e/exe 2>/dev/null)" = "$1" ] && echo "$e"; done | head -1; }
# relay_pid <run dir name>: relay, relay-split, relay-split-2, ...
relay_pid() { for e in $(ip netns pids "$R"); do tr '\0' ' ' < /proc/$e/cmdline 2>/dev/null | grep -q "/gateway/$1/runtime.json" && echo "$e"; done | head -1; }
# sing-box runs through a launcher here, so it is found by its command line.
sb_pid() { for e in $(ip netns pids "$R"); do tr '\0' ' ' < /proc/$e/cmdline 2>/dev/null | grep -q "sing-box.* run -c" && echo "$e"; done | head -1; }
# lease_count <forward id>: native lease requests the panel saw for a carrier.
lease_count() { python3 - "$STATE/native_lease_requests.jsonl" "$1" <<'PY'
import json, sys
count = 0
try:
    for line in open(sys.argv[1]):
        if int(json.loads(line).get("forward_id", 0)) == int(sys.argv[2]):
            count += 1
except OSError:
    pass
print(count)
PY
}
lease_count_above() { [ "$(lease_count "$1")" -gt "$2" ]; }
# usage_of <forward id>: the panel accepted usage with bytes for the carrier.
usage_of() { python3 - "$STATE/usage.jsonl" "$1" <<'PY'
import json, sys
total = 0
try:
    for line in open(sys.argv[1]):
        item = json.loads(line)
        if item.get("forward_id") == int(sys.argv[2]):
            total += item.get("bytes_in", 0) + item.get("bytes_out", 0)
except OSError:
    pass
sys.exit(0 if total > 0 else 1)
PY
}
# heartbeat_reports <forward id>: the last heartbeat carries the carrier's
# runtime health (ultra_status).
heartbeat_reports() { python3 - "$STATE/last_heartbeat.json" "$1" <<'PY'
import json, sys
body = json.load(open(sys.argv[1]))
ids = {int(item.get("forward_id", 0)) for item in body.get("ultra_status") or []}
sys.exit(0 if int(sys.argv[2]) in ids else 1)
PY
}

step "building the namespaces ($CASE)"
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
# A destination the LAN reaches directly (no relay): the cidr cases use it to
# show that what is not listed is not captured.
ip -n $U addr add 198.51.100.20/32 dev lo
ip -n $U route add 192.168.77.0/24 via 10.99.0.1
ok "router, LAN client and upstream namespaces are up"

step "starting the upstream relay and the panel"
ip netns exec $U python3 "$HERE/echo_server.py" 19080 >"$ROOT/echo.log" 2>&1 &
ip netns exec $U python3 -c "
import socket, threading
server = socket.socket(); server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind(('198.51.100.20', 19081)); server.listen(16)
def serve(conn):
    data = conn.recv(4096)
    conn.sendall(b'echo:direct:' + data); conn.close()
while True:
    conn, _ = server.accept()
    threading.Thread(target=serve, args=(conn,), daemon=True).start()" >"$ROOT/direct-echo.log" 2>&1 &
ip netns exec $U env FAKE_RELAY_HOST=198.51.100.10 FAKE_RELAY_LOG="$STREAMS" python3 "$HERE/fake_relay.py" 19000 19080 >"$ROOT/relay.log" 2>&1 &
echo 1 > "$STATE/native"
echo 198.51.100.10 > "$STATE/relay_host"
echo 19000 > "$STATE/relay_port"
echo 100 > "$STATE/native_ttl"
echo 0 > "$STATE/forward_count"
case "$CASE" in
	splits)     echo "50 51 52" > "$STATE/native_ids"; echo 240 > "$STATE/native_ttl" ;;
	split)      echo "50 51" > "$STATE/native_ids"; echo 240 > "$STATE/native_ttl" ;;
	cidr-split) echo "50 51" > "$STATE/native_ids"; echo 240 > "$STATE/native_ttl" ;;
	cidr)       echo 240 > "$STATE/native_ttl" ;;
esac
inr python3 "$HERE/mock_panel.py" "$STATE" 18080 >"$ROOT/panel.log" 2>&1 &
wait_for 10 test -f "$STATE/ready" && ok "mock panel ready" || bad "mock panel did not start"
# Without the gateway the LAN cannot reach the test destination at all.
[ "$(lan_echo 203.0.113.80 7777 baseline | cut -c1-3)" = "ERR" ] && ok "baseline: 203.0.113.80 is unreachable without the gateway" || bad "baseline destination is reachable; the test proves nothing"
lan_ok 198.51.100.20 19081 baseline && ok "baseline: 198.51.100.20 answers directly" || bad "the direct echo server is not reachable"

step "starting leikwand in gateway mode (case=$CASE scope=$SCOPE policy=$POLICY)"
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
case "$CASE" in
cidr|cidr-split)
	# The PVE layout: the SHIXP list (as packaged) plus the test prefix.
	{ cat "$SHIXP_LIST"; echo; echo "203.0.113.0/24"; } > "$ROOT/capture-v4.txt"
	cat >> "$ROOT/leikwan.uci" <<EOF
	option gateway_forward_id '50'
	option gateway_capture 'cidr'
	option gateway_split_cidr_file '$ROOT/capture-v4.txt'
	option gateway_tun_address '172.19.0.1/30'
	option gateway_tun_mtu '1280'
EOF
	if [ "$CASE" = "cidr-split" ]; then
		echo "192.0.2.0/24" > "$ROOT/split-b.txt"
		cat >> "$ROOT/leikwan.uci" <<EOF

config split 'b'
	option forward_id '51'
	option route_policy 'auto'
	option cidr_file '$ROOT/split-b.txt'
EOF
	fi
	;;
split)
	# The router layout, 1.1.x keys only (1.1.1 runs this case too).
	{ cat "$SHIXP_LIST"; echo; echo "203.0.113.0/25"; } > "$ROOT/split-v4.txt"
	cat >> "$ROOT/leikwan.uci" <<EOF
	option gateway_forward_id '50'
	option gateway_split_forward_id '51'
	option gateway_split_route_policy 'auto'
	option gateway_split_cidr_file '$ROOT/split-v4.txt'
EOF
	;;
splits)
	# Overlapping lists: 203.0.113.80 is in both, 203.0.113.200 only in b.
	echo "203.0.113.0/25" > "$ROOT/split-a.txt"
	printf '# comments and blank lines are fine\n\n203.0.113.0/24\n' > "$ROOT/split-b.txt"
	cat >> "$ROOT/leikwan.uci" <<EOF
	option gateway_forward_id '50'
	option gateway_split_forward_id '51'
	option gateway_split_route_policy 'auto'
	option gateway_split_cidr_file '$ROOT/split-a.txt'

config split 'b'
	option forward_id '52'
	option route_policy 'auto'
	option cidr_file '$ROOT/split-b.txt'

config split 'c'
	option enabled '0'
	option forward_id '53'
	option cidr_file '$ROOT/split-b.txt'
EOF
	;;
esac
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
wait_for 30 sh -c "[ \"\$(LEIKWAN_UCI_PATH='$ROOT/leikwan.uci' ip netns exec $R '$LEIKWAND' ctl gateway | python3 -c \"import json,sys; print(json.load(sys.stdin)['data']['slots'][0]['state'])\")\" = running ]" \
	&& ok "ctl gateway: the primary slot is running" || bad "primary slot: $(slots 's[:1]')"
[ "$(slots "(s[0]['role'], s[0]['carrier_forward_id'], s[0]['socks_port'])")" = "('primary', 50, 2080)" ] \
	&& ok "primary slot: carrier #50 on 127.0.0.1:2080" || bad "primary slot: $(slots 's[:1]')"

if [ "$CASE" = "full" ]; then
step "LAN data path"
reply=$(lan_echo 203.0.113.80 7777 lan-through-native)
case "$reply" in echo:*) ok "LAN client -> TUN -> sing-box -> relay -> echo";; *) bad "LAN data path: '$reply'";; esac
expect_carrier "the primary carrier carries the LAN" native-user 203.0.113.80 7777 primary
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
wait_for 30 usage_of 50 && ok "the relay's traffic is reported as usage of native carrier #50" || bad "no native usage reached the panel heartbeat"
wait_for 15 heartbeat_reports 50 && ok "the heartbeat reports carrier #50's runtime health" || bad "no ultra_status for #50 in the heartbeat"

step "panel refresh: one re-lease per 20 s, nothing restarts"
# Longer leases first, so a natural renewal cannot land inside the window.
echo 240 > "$STATE/native_ttl"
before=$(lease_count 50)
wait_for 30 lease_count_above 50 "$before" && ok "renewed onto a 240 s lease" || bad "no renewal onto the long lease"
sleep 3
before=$(lease_count 50)
echo 50 > "$STATE/action_refresh"
# Every heartbeat (5 s) repeats the refresh from here on.
if wait_for 15 lease_count_above 50 "$before"; then ok "a panel refresh re-leased the primary at once"; else bad "the panel refresh did not re-lease"; fi
t0=$(date +%s); first=$(lease_count 50)
sleep 12
[ "$(lease_count 50)" -eq "$first" ] && ok "refreshes repeated within 20 s are held back (2+ heartbeats, no re-lease)" \
	|| bad "re-leased again within 12 s of a forced re-lease ($first -> $(lease_count 50))"
if wait_for 20 lease_count_above 50 "$first"; then
	gap=$(( $(date +%s) - t0 ))
	[ "$gap" -ge 14 ] && ok "the held-back refresh ran once the 20 s window closed (+${gap}s)" || bad "deferred re-lease after only ${gap}s"
else
	bad "the held-back refresh never ran"
fi
rm -f "$STATE/action_refresh"
sleep 2
[ "$(pid_of "$ULTRA")" = "$RELAY_PID" ] && ok "relay kept running across the refreshes" || bad "relay restarted on refresh"
[ "$(sb_pid)" = "$SB_PID" ] && ok "sing-box kept running across the refreshes" || bad "sing-box restarted on refresh"
lan_ok 203.0.113.80 7777 after-refresh && ok "LAN path works after the refreshes" || bad "LAN path broke after the refreshes"
fi

if [ "$CASE" = "cidr" ] || [ "$CASE" = "cidr-split" ]; then
step "cidr capture"
WANT=$(python3 - "$RUN/gateway/sing-box.json" <<'PY'
import json, sys
config = json.load(open(sys.argv[1]))
tun = config["inbounds"][0]
assert "dns" not in config, "cidr capture must not install a DNS block"
assert not any(rule.get("action") == "hijack-dns" or rule.get("protocol") == "dns" for rule in config["route"]["rules"])
print(len(tun["route_address"]))
PY
)
LISTED=$(python3 - "$ROOT/capture-v4.txt" "$ROOT/split-b.txt" "$CASE" <<'PY'
import ipaddress, sys
files = [sys.argv[1]] + ([sys.argv[2]] if sys.argv[3] == "cidr-split" else [])
seen = set()
for name in files:
    for line in open(name):
        line = line.split("#")[0].strip()
        if not line:
            continue
        try:
            network = ipaddress.ip_network(line, strict=False)
        except ValueError:
            continue
        # The same filter as parseSplitCIDRs (Go's IsPrivate is RFC 1918
        # only; Python's is_private also covers the documentation ranges).
        rfc1918 = [ipaddress.ip_network(n) for n in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")]
        if network.version != 4 or network.prefixlen < 8:
            continue
        if any(network.network_address in n for n in rfc1918) or network.is_loopback or network.is_link_local or network.network_address.is_unspecified:
            continue
        seen.add(str(network))
print(len(seen))
PY
)
[ -n "$WANT" ] && ok "sing-box config: $WANT capture prefixes, no DNS block" || bad "sing-box config is not a cidr capture"
[ "$WANT" = "$LISTED" ] && ok "the capture is the union of the lists ($LISTED prefixes)" || bad "capture has $WANT prefixes, the lists $LISTED"
# sing-box adds the routes one by one after the TUN is up.
routes_in() { [ "$(ip netns exec $R ip route show table 2022 | grep -c 'dev lkwtun0')" = "$WANT" ] && ip netns exec $R ip rule show | grep -q '^9000:'; }
wait_for 30 routes_in
ROUTES=$(inr ip route show table 2022 | grep -c "dev lkwtun0")
[ "$ROUTES" = "$WANT" ] && ok "$ROUTES prefixes routed into lkwtun0 in table 2022" || bad "table 2022 has $ROUTES lkwtun0 routes, want $WANT"
inr ip rule show > "$ROOT/ip-rules.log"; inr ip route show table 2022 > "$ROOT/table-2022.log"
PREFS=$(awk -F: '$1 >= 8900 && $1 < 9100 { printf "%s ", $1 }' "$ROOT/ip-rules.log")
echo "    rule prefs: $PREFS"
case " $PREFS " in *" 8970 "*) ok "relay bypass at pref 8970";; *) bad "no bypass rule at 8970";; esac
# sing-box's auto_route rules: 9000-9003 plus the nop target it jumps to
# (rule index + 10), the same set 1.1.1 installs from the same tun options.
awk -F: '$1 >= 8900 && $1 < 9100 { print $1 }' "$ROOT/ip-rules.log" | python3 -c "
import sys
prefs = {int(x) for x in sys.stdin.read().split()}
sys.exit(0 if {8970, 9000, 9001, 9002, 9003} <= prefs <= {8970, 9000, 9001, 9002, 9003, 9010} else 1)" \
	&& ok "rules: bypass 8970, sing-box 9000-9003 (+ its nop 9010)" || bad "unexpected rule prefs: $PREFS"
inr ip route get 203.0.113.80 | grep -q "dev lkwtun0" && ok "a listed destination routes into the TUN" || bad "203.0.113.80: $(inr ip route get 203.0.113.80)"
inr ip route get 198.51.100.20 | grep -q "dev wan0" && ok "an unlisted destination keeps the main route" || bad "198.51.100.20: $(inr ip route get 198.51.100.20)"
inr ip route get 1.1.1.1 | grep -q "dev wan0" && ok "the default route (DNS, updates, VMs) is untouched" || bad "1.1.1.1: $(inr ip route get 1.1.1.1)"

step "cidr data path"
expect_carrier "a listed destination leaves over the primary carrier" native-user 203.0.113.80 7777 cidr-listed
expect_carrier "an unlisted destination is not captured" direct 198.51.100.20 19081 cidr-unlisted
if [ "$CASE" = "cidr-split" ]; then
	wait_for 30 sh -c "[ \"\$(LEIKWAN_UCI_PATH='$ROOT/leikwan.uci' ip netns exec $R '$LEIKWAND' ctl gateway | python3 -c \"import json,sys; s=json.load(sys.stdin)['data']['slots']; print(s[1]['state'] if len(s) > 1 else '')\")\" = running ]" \
		&& ok "split slot b is running" || bad "split slot: $(slots 's[1:]')"
	[ "$(slots "(s[1]['name'], s[1]['carrier_forward_id'], s[1]['socks_port'])")" = "('b', 51, 2081)" ] \
		&& ok "split b: carrier #51 on 127.0.0.1:2081" || bad "split slot: $(slots 's[1:]')"
	expect_carrier "the split list leaves over its own carrier" native-user-51 192.0.2.80 7777 cidr-split
	wait_for 30 usage_of 51 && ok "split carrier #51 usage reached the panel" || bad "no usage for split carrier #51"
	wait_for 15 heartbeat_reports 51 && ok "the heartbeat reports split carrier #51's runtime health" || bad "no ultra_status for #51"
fi
wait_for 30 usage_of 50 && ok "primary carrier #50 usage reached the panel" || bad "no usage for carrier #50"
wait_for 15 heartbeat_reports 50 && ok "the heartbeat reports carrier #50's runtime health" || bad "no ultra_status for #50"
fi

if [ "$CASE" = "splits" ]; then
step "split slots"
wait_for 40 sh -c "[ \"\$(LEIKWAN_UCI_PATH='$ROOT/leikwan.uci' ip netns exec $R '$LEIKWAND' ctl gateway | python3 -c \"import json,sys; s=json.load(sys.stdin)['data']['slots']; print(','.join(x['state'] for x in s))\")\" = running,running,running ]" \
	&& ok "three slots running (the disabled split is not a slot)" || bad "slots: $(slots 's')"
[ "$(slots "[(x['name'], x['role'], x['carrier_forward_id'], x['socks_port']) for x in s]")" = "[('primary', 'primary', 50, 2080), ('legacy', 'split', 51, 2081), ('b', 'split-2', 52, 2082)]" ] \
	&& ok "slots: primary #50/2080, legacy split #51/2081, split b #52/2082" || bad "slots: $(slots "[(x['name'], x['role'], x['carrier_forward_id'], x['socks_port']) for x in s]")"
LISTEN=$(inr ss -ltnH 2>/dev/null | awk '{print $4}' | sort | tr '\n' ' ')
for port in 2080 2081 2082; do
	case " $LISTEN " in *" 127.0.0.1:$port "*) ok "relay listening on 127.0.0.1:$port";; *) bad "nothing on 127.0.0.1:$port ($LISTEN)";; esac
done
for id in 50 51 52; do
	[ "$(lease_count $id)" -ge 1 ] && ok "native lease requested for carrier #$id" || bad "no lease request for carrier #$id"
done
[ "$(lease_count 53)" = "0" ] && ok "the disabled split's carrier is never leased" || bad "carrier #53 was leased"
python3 - "$RUN/gateway/sing-box.json" <<'PY' && ok "sing-box: split rules first, in configured order, then the catch-all" || bad "sing-box split rules"
import json, sys
config = json.load(open(sys.argv[1]))
tags = {o["tag"]: o for o in config["outbounds"]}
rules = [r for r in config["route"]["rules"] if r.get("outbound", "").startswith("split")]
assert [r["outbound"] for r in rules] == ["split", "split-2"], rules
assert rules[0]["ip_cidr"] == ["203.0.113.0/25"] and rules[1]["ip_cidr"] == ["203.0.113.0/24"], rules
assert tags["split"]["server_port"] == 2081 and tags["split-2"]["server_port"] == 2082, tags
PY

step "split data path (first match wins)"
expect_carrier "in both lists: the first split carries it" native-user-51 203.0.113.80 7777 overlap
expect_carrier "only in split b's list" native-user-52 203.0.113.200 7777 split-b
expect_carrier "in no list: the primary carrier" native-user 192.0.2.80 7777 primary

step "per-carrier usage and heartbeat"
for id in 50 51 52; do
	wait_for 30 usage_of $id && ok "usage of carrier #$id reached the panel" || bad "no usage for carrier #$id"
	wait_for 15 heartbeat_reports $id && ok "the heartbeat reports carrier #$id's runtime health" || bad "no ultra_status for #$id"
done

step "panel refresh of split b"
P0=$(relay_pid relay); P1=$(relay_pid relay-split); P2=$(relay_pid relay-split-2); SB=$(sb_pid)
[ -n "$P0" ] && [ -n "$P1" ] && [ -n "$P2" ] && [ -n "$SB" ] && ok "relays $P0/$P1/$P2, sing-box $SB" || bad "missing a process: '$P0' '$P1' '$P2' '$SB'"
b50=$(lease_count 50); b51=$(lease_count 51); b52=$(lease_count 52)
echo 52 > "$STATE/action_refresh"
wait_for 15 lease_count_above 52 "$b52" && ok "split b was re-leased" || bad "the refresh did not re-lease split b"
rm -f "$STATE/action_refresh"
sleep 3
[ "$(lease_count 50)" = "$b50" ] && [ "$(lease_count 51)" = "$b51" ] && ok "the other slots were not re-leased" || bad "other slots re-leased: #50 $b50->$(lease_count 50) #51 $b51->$(lease_count 51)"
[ "$(relay_pid relay)" = "$P0" ] && [ "$(relay_pid relay-split)" = "$P1" ] && [ "$(relay_pid relay-split-2)" = "$P2" ] \
	&& ok "no relay restarted" || bad "relays now $(relay_pid relay)/$(relay_pid relay-split)/$(relay_pid relay-split-2)"
[ "$(sb_pid)" = "$SB" ] && ok "sing-box kept running (the split rule stays)" || bad "sing-box restarted on a split refresh"
expect_carrier "split b still carries its list" native-user-52 203.0.113.200 7777 after-refresh

step "panel stop of split b"
b52=$(lease_count 52)
echo 52 > "$STATE/action_stop"
relay_b_gone() { [ -z "$(relay_pid relay-split-2)" ]; }
wait_for 20 relay_b_gone && ok "split b's relay stopped" || bad "split b's relay still running"
wait_for 20 sh -c "[ \"\$(LEIKWAN_UCI_PATH='$ROOT/leikwan.uci' ip netns exec $R '$LEIKWAND' ctl gateway | python3 -c \"import json,sys; s=json.load(sys.stdin)['data']['slots']; print(s[2]['state'])\")\" = panel_stopped ]" \
	&& ok "ctl gateway: split b is panel_stopped" || bad "split b: $(slots 's[2:]')"
[ "$(relay_pid relay)" = "$P0" ] && [ "$(relay_pid relay-split)" = "$P1" ] && ok "the primary and the first split kept running" || bad "the stop touched other relays"
wait_for 30 sh -c "ip netns exec $R ip link show lkwtun0" && sleep 2
expect_carrier "split b's list falls back to the primary carrier" native-user 203.0.113.200 7777 after-stop
expect_carrier "the first split still carries its list" native-user-51 203.0.113.80 7777 after-stop-a
rm -f "$STATE/action_stop"
sleep 12
[ "$(lease_count 52)" = "$b52" ] && ok "a stopped split is not re-leased (same grant generation)" || bad "split b was re-leased after the stop"
[ "$(slot_state 2)" = "panel_stopped" ] && ok "split b stays stopped until the grant changes" || bad "split b: $(slot_state 2)"
fi

if [ "$CASE" = "split" ]; then
step "split carrier (router layout)"
wait_for 40 sh -c "[ \"\$(LEIKWAN_UCI_PATH='$ROOT/leikwan.uci' ip netns exec $R '$LEIKWAND' ctl gateway | python3 -c \"import json,sys; s=json.load(sys.stdin)['data']['slots']; print(','.join(x['state'] for x in s))\")\" = running,running ]" \
	&& ok "both slots running" || bad "slots: $(slots 's')"
[ "$(slots "[(x['name'], x['role'], x['carrier_forward_id'], x['socks_port']) for x in s]")" = "[('primary', 'primary', 50, 2080), ('legacy', 'split', 51, 2081)]" ] \
	&& ok "slots: primary #50/2080, legacy split #51/2081" || bad "slots: $(slots "[(x['name'], x['role'], x['carrier_forward_id'], x['socks_port']) for x in s]")"
split_listening() { ip netns exec $R ss -ltnH | awk '{print $4}' | grep -qx '127.0.0.1:2081'; }
wait_for 30 split_listening && ok "split relay listening on 127.0.0.1:2081" || bad "nothing on 127.0.0.1:2081"
for id in 50 51; do
	[ "$(lease_count $id)" -ge 1 ] && ok "native lease requested for carrier #$id" || bad "no lease request for carrier #$id"
done
SPLIT_N=$(grep -cE '^[0-9]' "$ROOT/split-v4.txt")
python3 - "$RUN/gateway/sing-box.json" "$SPLIT_N" <<'PY' && ok "sing-box: the split outbound (2081) and its $SPLIT_N-prefix rule ahead of the catch-all" || bad "sing-box split outbound/rule"
import json, sys
config = json.load(open(sys.argv[1]))
tags = {o["tag"]: o for o in config["outbounds"]}
assert tags["split"]["server_port"] == 2081, tags["split"]
rules = config["route"]["rules"]
index = [i for i, r in enumerate(rules) if r.get("outbound") == "split"]
assert len(index) == 1 and len(rules[index[0]]["ip_cidr"]) == int(sys.argv[2]), index
PY
expect_carrier "the SHIXP list leaves over the split carrier" native-user-51 203.0.113.80 7777 split-listed
expect_carrier "everything else leaves over the primary carrier" native-user 192.0.2.80 7777 split-unlisted
for id in 50 51; do
	wait_for 30 usage_of $id && ok "usage of carrier #$id reached the panel" || bad "no usage for carrier #$id"
	wait_for 15 heartbeat_reports $id && ok "the heartbeat reports carrier #$id's runtime health" || bad "no ultra_status for #$id"
done

step "panel refresh of the split carrier"
P0=$(relay_pid relay); P1=$(relay_pid relay-split); SB=$(sb_pid)
SBSUM=$(sha256sum "$RUN/gateway/sing-box.json" | cut -c1-64)
[ -n "$P0" ] && [ -n "$P1" ] && [ -n "$SB" ] && ok "relays $P0/$P1, sing-box $SB" || bad "missing a process: '$P0' '$P1' '$SB'"
b50=$(lease_count 50); b51=$(lease_count 51)
echo 51 > "$STATE/action_refresh"
wait_for 15 lease_count_above 51 "$b51" && ok "the split carrier was re-leased" || bad "the refresh did not re-lease the split"
rm -f "$STATE/action_refresh"
sleep 3
[ "$(lease_count 50)" = "$b50" ] && ok "the primary was not re-leased" || bad "primary re-leased: $b50 -> $(lease_count 50)"
[ "$(relay_pid relay)" = "$P0" ] && [ "$(relay_pid relay-split)" = "$P1" ] && ok "no relay restarted" || bad "relays now $(relay_pid relay)/$(relay_pid relay-split)"
[ "$(sb_pid)" = "$SB" ] && ok "sing-box kept running" || bad "sing-box restarted on a split refresh"
[ "$(sha256sum "$RUN/gateway/sing-box.json" | cut -c1-64)" = "$SBSUM" ] && ok "the split outbound and rule stay in sing-box (config unchanged)" || bad "sing-box config changed on a split refresh"
expect_carrier "the split still carries its list" native-user-51 203.0.113.80 7777 split-after-refresh
fi

# Keep the relays' runtime configs (the upgrade comparison diffs them).
for f in "$RUN"/gateway/*/runtime.json; do [ -f "$f" ] && cp "$f" "$ROOT/runtime-$(basename "$(dirname "$f")").json"; done

step "revocation"
echo 0 > "$STATE/native"
relay_gone() { [ -z "$(pid_of "$ULTRA")" ]; }
wait_for 40 relay_gone && ok "every relay stopped after the panel withdrew native outbound" || bad "relay still running after revocation"
ip netns exec $R ip link show lkwtun0 >/dev/null 2>&1 && bad "sing-box kept capturing after revocation" || ok "capture stopped (lkwtun0 gone)"
inr ip rule show | grep -q "^8970:" && bad "bypass rules left behind" || ok "every bypass rule removed"
inr ip rule show | grep -qE "^90[0-9][0-9]:" && bad "sing-box rules left behind" || ok "every sing-box rule removed"
[ "$(inr ip route show table 2022 2>/dev/null | wc -l)" = "0" ] && ok "table 2022 is empty" || bad "table 2022 left behind"
case "$(gw "g.get('last_error','')")" in *"not granted"*) ok "LuCI sees why";; *) bad "status error: $(gw "g.get('last_error')")";; esac

printf '\n[%s] %d passed, %d failed (logs in %s)\n' "$CASE" "$PASS" "$FAIL" "$ART"
[ "$FAIL" -eq 0 ]
