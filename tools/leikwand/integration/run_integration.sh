#!/bin/bash
# End-to-end integration test for the OpenWrt convergence agent.
#
# It runs the REAL daemon against the REAL ultra-client runtime with a mock
# panel, and asserts the properties that matter operationally:
#   - onboarding and configuration pull
#   - runtime supervision and readiness
#   - the Ultra control-socket lease protocol (byte compatible)
#   - containment when the panel has no usable candidate
#   - layer-1 high availability: a dead runtime is restored
#   - the panel's out-of-band stop command is honoured
#
# Everything runs unprivileged in a scratch directory; no /etc or /var is
# touched. Usage: ./run_integration.sh <leikwand-binary> <ultra-client-binary>
set -uo pipefail

LEIKWAND="${1:?path to the leikwand binary}"
ULTRA="${2:?path to the ultra-client binary}"
ART=/tmp/leikwand-it-artifacts
rm -rf "$ART"
ROOT=$(mktemp -d /tmp/leikwand-it-XXXXXX)
STATE="$ROOT/panel-state"
DATA="$ROOT/data"
RUN="$ROOT/run"
mkdir -p "$STATE" "$DATA" "$RUN"

PANEL_PORT=18080
LISTEN_PORT=10001
RELAY_PORT=19000
ECHO_PORT=19080
PASS=0
FAIL=0
DAEMON_PID=""

cleanup() {
	# Keep the evidence: a failing integration run is worthless without the
	# daemon's own view of what happened.
	mkdir -p "$ART"
	for f in daemon.log panel.log relay.log echo.log usage.jsonl usage_totals.json; do
		[ -f "$ROOT/$f" ] && cp "$ROOT/$f" "$ART/$f"
	done
	for f in status.json status2.json status3.json status4.json forwards.json forwards2.json diag.json; do
		[ -f "$ROOT/$f" ] && cp "$ROOT/$f" "$ART/$f"
	done
	[ -n "$DAEMON_PID" ] && kill "$DAEMON_PID" 2>/dev/null
	pkill -f "$ROOT" 2>/dev/null
	[ -n "${PANEL_PID:-}" ] && kill "$PANEL_PID" 2>/dev/null
	[ -n "${RELAY_PID:-}" ] && kill "$RELAY_PID" 2>/dev/null
	[ -n "${ECHO_PID:-}" ] && kill "$ECHO_PID" 2>/dev/null
}
trap cleanup EXIT

ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
step() { printf '\n== %s ==\n' "$1"; }

# jsonq <file> <python-expression over d>
jsonq() {
	python3 -c "
import json,sys
try:
    d=json.load(open(sys.argv[1]))
except Exception as e:
    print('PARSE_ERROR', e); sys.exit(0)
print($2)
" "$1" 2>/dev/null
}

wait_for() { # wait_for <seconds> <command...>
	local deadline=$(( $(date +%s) + $1 )); shift
	while [ "$(date +%s)" -lt "$deadline" ]; do
		if "$@" >/dev/null 2>&1; then return 0; fi
		sleep 0.5
	done
	return 1
}

# runtime_pids resolves the supervised runtime by its /proc/<pid>/exe link.
# Matching on the command line instead would also match this very script, whose
# arguments contain the runtime path — a mistake that makes the harness kill
# itself instead of the runtime.
runtime_pids() {
	local want
	want=$(readlink -f "$1")
	for entry in /proc/[0-9]*; do
		[ -d "$entry" ] || continue
		local exe
		exe=$(readlink -f "$entry/exe" 2>/dev/null) || continue
		[ "$exe" = "$want" ] && echo "${entry#/proc/}"
	done
}

port_open() { python3 -c "
import socket,sys
s=socket.socket(); s.settimeout(0.4)
sys.exit(0 if s.connect_ex(('127.0.0.1', int(sys.argv[1])))==0 else 1)" "$1"; }

# forward_echo sends a marker through the supervised listener and returns the
# response — the only assertion that proves the whole data path works.
# The response must be read to completion: TCP may deliver it in any number of
# segments, and a client that closes after a partial recv() flushes an RST at
# the runtime (unread bytes in the receive buffer), which both kills the
# session this step is supposed to observe and makes the failure look like a
# data-path fault.
forward_echo() { python3 -c "
import socket,sys
try:
    s=socket.socket(); s.settimeout(8)
    s.connect(('127.0.0.1', int(sys.argv[1])))
    s.sendall(sys.argv[2].encode())
    chunks=b''
    while True:
        part=s.recv(4096)
        if not part: break
        chunks+=part
        if b'echo:' in chunks and len(chunks) >= 5+len(sys.argv[2]): break
    print(chunks.decode(errors='replace'))
    s.close()
except Exception as e:
    print('ERR', e)" "$1" "$2"; }

ctl() { LEIKWAN_UCI_PATH="$ROOT/leikwan.uci" "$LEIKWAND" ctl "$@"; }

step "starting the mock panel and a candidate listener"
python3 "$(dirname "$0")/mock_panel.py" "$STATE" "$PANEL_PORT" >"$ROOT/panel.log" 2>&1 &
PANEL_PID=$!
# The relay speaks the real provider handshake and splices to a local echo
# server, so a healthy candidate can actually carry traffic.
python3 "$(dirname "$0")/echo_server.py" "$ECHO_PORT" >"$ROOT/echo.log" 2>&1 &
ECHO_PID=$!
python3 "$(dirname "$0")/fake_relay.py" "$RELAY_PORT" "$ECHO_PORT" >"$ROOT/relay.log" 2>&1 &
RELAY_PID=$!
sleep 1
wait_for 5 port_open "$ECHO_PORT" && ok "echo target listening on $ECHO_PORT" || bad "echo target did not start"
wait_for 5 port_open "$RELAY_PORT" && ok "fake relay listening on $RELAY_PORT" || bad "fake relay did not start"
wait_for 10 test -f "$STATE/ready" && ok "mock panel listening on 127.0.0.1:$PANEL_PORT" || bad "mock panel did not start"

step "writing the router configuration"
cat > "$ROOT/leikwan.uci" <<EOF
config leikwan 'main'
	option enabled '1'
	option panel_url 'http://127.0.0.1:$PANEL_PORT'
	# Never let a test reach the production fallback panel: it ships in the
	# binary, and one transport hiccup against the mock would send it a fake
	# token (the real panel answers 401, which reads like an auth bug). A
	# closed port keeps the failure local and visible.
	option fallback_panel_url 'http://127.0.0.1:1'
	# The mock panel is on loopback. The Panel egress pin would bind panel
	# sockets to the physical WAN, and a device-bound dial to 127.0.0.1 cannot
	# succeed, so the daemon would fall through to its production fallback URL
	# and see a 401. A router in service never points at loopback, so disabling
	# the pin here tests the daemon rather than an impossible deployment.
	option panel_egress_interface 'none'
	# router edition: no mode option
	option poll_interval '2'
	option heartbeat_interval '2'
	option health_interval '3'
	option cold_start_grace '20'
	option restart_cooldown '3'
	option lease_renew_lead '90'
	option max_candidates '4'
	option log_level 'debug'
	option data_dir '$DATA'
	option run_dir '$RUN'
	option runtime_bin '$ULTRA'
EOF
# Point the mock panel at a real, reachable target so the data path is testable.
echo "127.0.0.1:$ECHO_PORT" > "$STATE/target"
echo "$RELAY_PORT" > "$STATE/relay_port"
ok "configuration written (data=$DATA run=$RUN)"

step "starting the agent before enrolment"
LEIKWAN_UCI_PATH="$ROOT/leikwan.uci" "$LEIKWAND" run >"$ROOT/daemon.log" 2>&1 &
DAEMON_PID=$!
wait_for 15 test -S "$RUN/ctl.sock" && ok "control socket created" || bad "control socket never appeared"

ctl status > "$ROOT/status.json" 2>"$ROOT/status.err"
[ "$(jsonq "$ROOT/status.json" "d['ok']")" = "True" ] && ok "ctl status returns ok" || bad "ctl status failed: $(cat "$ROOT/status.err")"
[ "$(jsonq "$ROOT/status.json" "d['data']['enrolled']")" = "False" ] && ok "reports not enrolled before onboarding" || bad "enrolled flag wrong before onboarding"
[ "$(jsonq "$ROOT/status.json" "d['data']['runtime_present']")" = "True" ] && ok "runtime binary detected" || bad "runtime binary not detected"

step "onboarding by writing the panel-issued agent token"
python3 - "$DATA" "$PANEL_PORT" <<'PY'
import json, os, sys
data, port = sys.argv[1], sys.argv[2]
os.makedirs(data, exist_ok=True)
json.dump({
    "agent_token": "it-agent-token",
    "device_id": "openwrt-integration",
    "device_name": "Integration Router",
    "client_id": 42,
    "client_name": "Integration Router",
    "master_url": "http://127.0.0.1:%s" % port,
    "enrolled_at": "2020-01-01T00:00:00Z",
    "enroll_method": "integration",
}, open(os.path.join(data, "secret.json"), "w"))
PY
chmod 600 "$DATA/secret.json"
ok "secret written"

# The daemon notices an out-of-band credential within one poll cycle, so poll
# for the state instead of guessing a sleep.
ENROLLED=0
for _ in $(seq 1 40); do
	ctl status > "$ROOT/status2.json" 2>"$ROOT/status2.err"
	if [ "$(jsonq "$ROOT/status2.json" "d['data']['enrolled']")" = "True" ]; then ENROLLED=1; break; fi
	sleep 1
done
[ "$ENROLLED" = "1" ] && ok "agent picked up the credential file" || bad "agent never adopted the token"
[ "$(jsonq "$ROOT/status2.json" "d['data']['enrolled']")" = "True" ] && ok "agent reports enrolled" || bad "agent did not pick up the token"
[ "$(jsonq "$ROOT/status2.json" "d['data']['client_id']")" = "42" ] && ok "panel client id adopted" || bad "client id not adopted"

step "the panel's register command line"
ctl sign_out > "$ROOT/signout.json" 2>/dev/null
SIGNED_OUT=0
for _ in $(seq 1 20); do
	ctl status > "$ROOT/status3.json" 2>/dev/null
	if [ "$(jsonq "$ROOT/status3.json" "d['data']['enrolled']")" = "False" ]; then SIGNED_OUT=1; break; fi
	sleep 1
done
[ "$SIGNED_OUT" = "1" ] && ok "sign_out cleared the credential" || bad "sign_out left the device enrolled"
CLI_REG=$(ctl register client_id=42 agent_token=it-agent-token 2>&1)
echo "$CLI_REG" | grep -q '"ok":true' && ok "ctl register accepted the card's command" || bad "ctl register failed: $CLI_REG"
ENROLLED_AGAIN=0
for _ in $(seq 1 20); do
	ctl status > "$ROOT/status4.json" 2>/dev/null
	if [ "$(jsonq "$ROOT/status4.json" "d['data']['enrolled']")" = "True" ]; then ENROLLED_AGAIN=1; break; fi
	sleep 1
done
[ "$ENROLLED_AGAIN" = "1" ] && ok "device is enrolled after the CLI register" || bad "not enrolled after ctl register"

step "runtime supervision"
wait_for 40 port_open "$LISTEN_PORT" && ok "forward listen port $LISTEN_PORT is serving" || bad "forward port never became ready"
ctl forwards > "$ROOT/forwards.json" 2>/dev/null
[ "$(jsonq "$ROOT/forwards.json" "len(d['data'])")" = "1" ] && ok "exactly one forward is supervised" || bad "forward count wrong"
[ "$(jsonq "$ROOT/forwards.json" "d['data'][0]['status']")" = "running" ] && ok "forward status is running" || bad "forward status is $(jsonq "$ROOT/forwards.json" "d['data'][0]['status']")"
[ "$(jsonq "$ROOT/forwards.json" "d['data'][0]['target']")" = "127.0.0.1:$ECHO_PORT" ] && ok "forward target matches the panel" || bad "forward target wrong"

step "data path: real traffic through the supervised forward"
# The Ultra provider protocol transforms application bytes on the upstream
# leg, and this fake relay deliberately does not implement that transform.
# What is asserted is the round trip itself: bytes written to the listener
# reached the echo target through runtime -> relay and came back carrying the
# target's own prefix. That is the strongest claim this harness can honestly
# make without reimplementing the provider transform.
DATA_OK=0
for _ in $(seq 1 20); do
	RESP=$(forward_echo "$LISTEN_PORT" "hello-integration")
	case "$RESP" in echo:*) DATA_OK=1; break ;; esac
	sleep 1
done
[ "$DATA_OK" = "1" ] && ok "round trip reached the target and returned (listener -> runtime -> relay -> target)" || bad "data path failed (last response: $RESP)"

step "Ultra lease protocol (real control socket)"
wait_for 40 test -f "$STATE/last_lease_request.json" && ok "agent requested a lease" || bad "no lease request reached the panel"
REQ="$STATE/last_lease_request.json"
[ "$(jsonq "$REQ" "d['forwards'][0]['purpose']")" = "ultra" ] && ok "lease purpose is 'ultra'" || bad "lease purpose is $(jsonq "$REQ" "d['forwards'][0]['purpose']")"
[ "$(jsonq "$REQ" "d['forwards'][0]['selection_mode']")" = "auto" ] && ok "lease asks the runtime to auto-select" || bad "selection_mode wrong"
[ "$(jsonq "$REQ" "d['forwards'][0]['max_candidates']")" = "4" ] && ok "lease asks for up to 4 candidates" || bad "max_candidates wrong"
[ "$(jsonq "$REQ" "d['device_id']")" = "openwrt-integration" ] && ok "lease carries the router device id" || bad "device id missing from the lease request"

step "sealed lease v1 (device lease key)"
# The mock panel runs in "optional" sealing mode, so the lease that carried the
# data-path round trip above was sealed to this router's own key and opened by
# the daemon before it reached the runtime.
LEASE_KEY_HEADER=$(cat "$STATE/last_lease_key_header" 2>/dev/null)
[ "$(python3 -c "
import base64,sys
v=sys.argv[1]
try:
    raw=base64.urlsafe_b64decode(v+'='*(-len(v)%4))
except Exception:
    raw=b''
print(len(raw)==65 and raw[:1]==b'\x04' and '=' not in v)" "$LEASE_KEY_HEADER")" = "True" ] \
	&& ok "lease request carries a 65-byte P-256 X-Leikwan-Lease-Key" || bad "lease key header missing or malformed"
case "$(cat "$STATE/last_lease_sealed" 2>/dev/null)" in
	sealed*) ok "panel answered with sealed leases ($(cat "$STATE/last_lease_sealed"))" ;;
	*) bad "panel did not seal the lease ($(cat "$STATE/last_lease_sealed" 2>/dev/null))" ;;
esac
[ -f "$DATA/lease_key.json" ] && ok "device lease key persisted in the data directory" || bad "lease_key.json missing"
[ "$(stat -c %a "$DATA/lease_key.json" 2>/dev/null)" = "600" ] && ok "lease_key.json is owner-only (0600)" || bad "lease_key.json mode is $(stat -c %a "$DATA/lease_key.json" 2>/dev/null)"
grep -q "lease_unseal_failed" "$ROOT/daemon.log" && bad "daemon reported lease_unseal_failed" || ok "every sealed lease opened"
if [ -f "$DATA/lease_key.json" ]; then
	SCALAR=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['private_scalar'])" "$DATA/lease_key.json" 2>/dev/null)
	if [ -n "$SCALAR" ] && grep -rqF "$SCALAR" "$ROOT/daemon.log" "$STATE" "$DATA/secret.json" 2>/dev/null; then
		bad "device private key leaked into a log, the panel or secret.json"
	else
		ok "device private key never left lease_key.json"
	fi
	grep -qF "pass-1" "$ROOT/daemon.log" && bad "relay credential appeared in the daemon log" || ok "relay credential not logged"
fi

step "a refused grant never drops a healthy session"
# The panel now reports that no approved candidate exists. A forward that
# already holds a valid lease must keep serving: refusing a *new* grant is a
# capacity decision, not a revocation. Dropping traffic here would turn a
# transient control-plane hiccup into a customer outage.
echo "none" > "$STATE/lease_mode"
sleep 8
kill -0 "$DAEMON_PID" 2>/dev/null && ok "daemon survived the refusal" || bad "daemon died"
ctl forwards > "$ROOT/forwards2.json" 2>/dev/null
[ "$(jsonq "$ROOT/forwards2.json" "len(d['data'])")" = "1" ] && ok "forward is still supervised" || bad "forward was dropped"
RESP=$(forward_echo "$LISTEN_PORT" "still-serving")
case "$RESP" in echo:*) ok "existing session kept serving through the refusal" ;; *) bad "traffic was dropped on a refused grant ($RESP)" ;; esac
echo "two" > "$STATE/lease_mode"

step "heartbeat reporting"
wait_for 20 test -f "$STATE/last_heartbeat.json" && ok "heartbeat reached the panel" || bad "no heartbeat received"
HB="$STATE/last_heartbeat.json"
[ "$(jsonq "$HB" "bool(d['agent_version'])")" = "True" ] && ok "heartbeat carries the agent version" || bad "agent_version missing"

step "usage accounting"
# The traffic above was real; the panel must be billed for it, and a snapshot
# must never be counted twice (the agent only clears its queue on the ack).
wait_for 20 test -s "$STATE/usage.jsonl" && ok "usage snapshots reached the panel" || bad "no usage reached the panel"
USAGE_SUM=$(python3 -c "
import json,sys
total=0
for line in open(sys.argv[1]):
    item=json.loads(line)
    total+=int(item.get('bytes_in',0))+int(item.get('bytes_out',0))
print(total)" "$STATE/usage.jsonl" 2>/dev/null)
[ "${USAGE_SUM:-0}" -gt 0 ] && ok "billed bytes are non-zero ($USAGE_SUM)" || bad "usage totals are zero"
DUPES=$(python3 -c "
import json,sys
seen=set(); dupes=0
for line in open(sys.argv[1]):
    item=json.loads(line)
    key=(item.get('forward_id'), item.get('sequence'))
    if key in seen: dupes+=1
    seen.add(key)
print(dupes)" "$STATE/usage.jsonl" 2>/dev/null)
[ "${DUPES:-0}" = "0" ] && ok "no usage snapshot was billed twice" || bad "$DUPES duplicate usage snapshots"

step "configuration sync: a line rotation reaches the panel"
# A new policy generation is the panel's line rotation. The router must pull it
# on its next poll; the leases that follow it are only requested when the
# current grant nears renewal (LeaseRenewLeadSecs), which is minutes away for a
# grant that was just installed — so the pull is what proves delivery here.
# The VM suite makes the same distinction for the same reason.
NEWGEN=$(printf 'b%.0s' $(seq 1 64))
echo "$NEWGEN" > "$STATE/policy_generation"
ROTATED=0
for _ in $(seq 1 40); do
	if grep -q "$NEWGEN" "$STATE/generations.jsonl" 2>/dev/null; then ROTATED=1; break; fi
	sleep 1
done
[ "$ROTATED" = "1" ] && ok "agent pulled the new generation" || bad "rotation never reached the panel"
RESP=$(forward_echo "$LISTEN_PORT" "after-rotation")
case "$RESP" in echo:*) ok "traffic kept flowing across the rotation" ;; *) bad "traffic broke on rotation ($RESP)" ;; esac

step "panel unreachable: the installed grant keeps serving"
# A 503 from every endpoint is what a router sees when the panel is down.
# Nothing on the data path depends on it, so an existing lease must keep
# serving; only a *refusal* may stop traffic.
echo "1" > "$STATE/offline"
OFFLINE_SEEN=0
for _ in $(seq 1 30); do
	ctl status > "$ROOT/status_offline.json" 2>/dev/null
	if [ "$(jsonq "$ROOT/status_offline.json" "bool(d['data']['last_pull_error'])")" = "True" ]; then OFFLINE_SEEN=1; break; fi
	sleep 1
done
[ "$OFFLINE_SEEN" = "1" ] && ok "agent reports the panel as unreachable" || bad "outage was not noticed"
RESP=$(forward_echo "$LISTEN_PORT" "during-outage")
case "$RESP" in echo:*) ok "traffic kept flowing while the panel was unreachable" ;; *) bad "traffic dropped during the outage ($RESP)" ;; esac
kill -0 "$DAEMON_PID" 2>/dev/null && ok "daemon survived the outage" || bad "daemon died during the outage"

step "panel recovery"
rm -f "$STATE/offline"
RECOVERED=0
for _ in $(seq 1 40); do
	ctl status > "$ROOT/status_recovered.json" 2>/dev/null
	if [ "$(jsonq "$ROOT/status_recovered.json" "d['data']['last_pull_error']")" = "" ]; then RECOVERED=1; break; fi
	sleep 1
done
[ "$RECOVERED" = "1" ] && ok "agent reconnected to the panel" || bad "agent never reconnected"
RESP=$(forward_echo "$LISTEN_PORT" "after-recovery")
case "$RESP" in echo:*) ok "traffic flows after recovery" ;; *) bad "traffic did not recover ($RESP)" ;; esac

step "layer-1 high availability: runtime death is recovered"
ULTRA_PID=$(runtime_pids "$ULTRA" | head -1)
if [ -n "$ULTRA_PID" ]; then
	kill -9 "$ULTRA_PID" 2>/dev/null
	ok "killed the forwarding runtime (pid $ULTRA_PID)"
	RESTORED=0
	for _ in $(seq 1 40); do
		sleep 1
		NEW_PID=$(runtime_pids "$ULTRA" | head -1)
		if [ -n "$NEW_PID" ] && [ "$NEW_PID" != "$ULTRA_PID" ] && port_open "$LISTEN_PORT"; then RESTORED=1; break; fi
	done
	[ "$RESTORED" = "1" ] && ok "runtime was restarted and the port is serving again" || bad "runtime was not recovered"
else
	bad "could not find the runtime process"
fi

step "panel out-of-band command"
echo "3" > "$STATE/action_stop"
STOPPED=0
for _ in $(seq 1 30); do
	sleep 1
	COUNT=$(ctl forwards 2>/dev/null | python3 -c "import json,sys; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo 1)
	if [ "$COUNT" = "0" ]; then STOPPED=1; break; fi
done
[ "$STOPPED" = "1" ] && ok "panel stop action stopped the forward" || bad "panel stop action was ignored"

step "diagnostics"
ctl diagnose > "$ROOT/diag.json" 2>/dev/null
[ "$(jsonq "$ROOT/diag.json" "d['ok']")" = "True" ] && ok "ctl diagnose returns ok" || bad "diagnose failed"
[ "$(jsonq "$ROOT/diag.json" "any(c['name']=='panel_reachable' and c['ok'] for c in d['data']['checks'])")" = "True" ] && ok "panel reachability check passed" || bad "panel reachability check failed"

step "daemon log (last 40 lines)"
tail -40 "$ROOT/daemon.log" 2>/dev/null || true

step "result"
printf '%d passed, %d failed\n' "$PASS" "$FAIL"
printf 'artifacts kept in /tmp/leikwand-it-artifacts\n'
[ "$FAIL" -eq 0 ] || exit 1
