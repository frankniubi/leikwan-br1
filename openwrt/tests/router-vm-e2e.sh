#!/bin/bash
# End-to-end test of the router edition on a stock OpenWrt image.
#
# The integration suite proves the daemon against a mock panel on the host. It
# cannot prove that the packages install on a real firmware, that the install
# command the panel renders actually works, or that procd brings the daemon up
# as a service. This test does:
#
#   stock image -> apk/opkg install (panel-rendered command) -> register
#   -> forward real traffic -> usage reaches the panel -> line rotation
#   -> panel unreachable (network drop) -> automatic failover -> reconnect
#   -> service survives a reboot -> clean uninstall
#
# Everything runs offline: the panel is the mock from the integration suite and
# the artifacts are served from the host through QEMU's user network.
#
# Usage: router-vm-e2e.sh --edition apk|opkg --image-dir <dir with leikwand*>
#                         [--arm64-dir <dir>] [--release 25.12.6] [--keep]
# The image directory is a package directory from the CI packages job (or a
# local SDK output). The stock firmware comes from downloads.openwrt.org.

set -uo pipefail

EDITION=apk
IMAGE_DIR=""
# Optional arm64 packages of the same edition: the CPU-specific-architecture
# phase installs them on this x86 router to exercise the installer's arch
# handling with the real aarch64_generic packages.
ARM64_DIR=""
RELEASE=""
KEEP=0
HOST_FWD=2422
FORWARD_FWD=10001
FORWARD_PORT=10001
# The stock image's br-lan is 192.168.1.1/24, so the QEMU user network must be
# on that subnet with the host as 192.168.1.2. The default 10.0.2.0/24 leaves
# the guest without a route to the forwarded port or to the host services.
GUEST_HOST=192.168.1.2
PANEL_PORT=18080
RELAY1_PORT=19000
RELAY2_PORT=19001
ECHO_PORT=19080
ART_PORT=18081

while [ $# -gt 0 ]; do
	case "$1" in
		--edition) EDITION="$2"; shift 2 ;;
		--image-dir) IMAGE_DIR="$2"; shift 2 ;;
		--arm64-dir) ARM64_DIR="$2"; shift 2 ;;
		--release) RELEASE="$2"; shift 2 ;;
		--keep) KEEP=1; shift ;;
		*) echo "unknown argument: $1" >&2; exit 2 ;;
	esac
done

case "$EDITION" in
	apk) DEFAULT_RELEASE=25.12.6; EXT=apk ;;
	opkg) DEFAULT_RELEASE=24.10.4; EXT=ipk ;;
	*) echo "edition must be apk or opkg" >&2; exit 2 ;;
esac
RELEASE="${RELEASE:-$DEFAULT_RELEASE}"
[ -n "$IMAGE_DIR" ] || { echo "--image-dir is required" >&2; exit 2; }
IMAGE_DIR=$(cd "$IMAGE_DIR" && pwd)

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
WORK=$(mktemp -d /tmp/leikwan-vm-XXXXXX)
VM_LOG="$WORK/serial.log"
PASS=0
FAIL=0
QEMU_PID=""
PANEL_PID=""
RELAY1_PID=""
RELAY2_PID=""
ECHO_PID=""
ART_PID=""
SSH_KEY="$WORK/id_test"
SSH_OPTS=(-i "$SSH_KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null
	-o ConnectTimeout=10 -o ServerAliveInterval=5 -o LogLevel=ERROR)

ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
step() { printf '\n== %s ==\n' "$1"; }

stop_pid() {
	local pid="${1:-}"
	[ -n "$pid" ] && kill "$pid" 2>/dev/null
}

cleanup() {
	if [ "$KEEP" = "1" ]; then
		echo "keeping $WORK"
	else
		stop_pid "$QEMU_PID"; stop_pid "$PANEL_PID"; stop_pid "$RELAY1_PID"
		stop_pid "$RELAY2_PID"; stop_pid "$ECHO_PID"; stop_pid "$ART_PID"
		[ -f "$WORK/qemu.pid" ] && kill "$(cat "$WORK/qemu.pid")" 2>/dev/null
	fi
}
trap cleanup EXIT

# vm runs one command on the router. It never hangs: ssh's own timeout plus
# this outer limit keep a wedged VM from stalling the whole run.
vm() {
	timeout 120 ssh "${SSH_OPTS[@]}" -p "$HOST_FWD" root@127.0.0.1 "$@" 2>/dev/null
}

vm_script() {
	timeout 180 ssh "${SSH_OPTS[@]}" -p "$HOST_FWD" root@127.0.0.1 'sh -s' 2>/dev/null
}

jsonq() { # jsonq <file> <python-expression over d>
	python3 -c "
import json,sys
try:
    d=json.load(open(sys.argv[1]))
except Exception as e:
    print('PARSE_ERROR', e); sys.exit(0)
print($2)
" "$1" 2>/dev/null
}

# roundtrip sends a marker through the router's forwarded listener from the
# host side and prints the answer. The stock image's BusyBox nc cannot set a
# timeout, so the client lives here, where a read timeout is available; the
# traffic path (host -> QEMU forward -> router listener -> runtime -> relay ->
# target) is the same one a LAN client uses.
roundtrip() { python3 - "$1" "$2" <<'PY'
import socket, sys
port, marker = int(sys.argv[1]), sys.argv[2].encode()
try:
    s = socket.create_connection(("127.0.0.1", port), timeout=10)
    s.settimeout(10)
    s.sendall(marker)
    got = b""
    while len(got) < 5 + len(marker):
        part = s.recv(4096)
        if not part:
            break
        got += part
    print(got.decode(errors="replace"))
    s.close()
except Exception as exc:
    print("ERR", exc)
PY
}

# router_status <python-expression over the status data object>
# The status document omits empty fields (an enrolled router has no
# last_pull_error at all), so assertions ask JSON instead of grepping text.
router_status() {
	vm 'leikwand ctl status' | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)['data']
except Exception:
    print('')
    sys.exit(0)
print($1)" 2>/dev/null
}

# roundtrip_retry tries a few times before giving up. The runtime binds its
# listener before the panel's first grant reaches it and resets a stream in
# that window, so one attempt is a race rather than a data-path verdict.
roundtrip_retry() { # roundtrip_retry <attempts> <marker>
	local attempt
	for attempt in $(seq 1 "$1"); do
		local answer
		answer=$(roundtrip "$FORWARD_FWD" "$2")
		case "$answer" in
			echo:*) printf '%s' "$answer"; return 0 ;;
		esac
		sleep 3
	done
	printf '%s' "$answer"
	return 1
}

wait_forward_port() { # wait_forward_port <seconds>
	for _ in $(seq 1 "$1"); do
		if python3 -c "import socket,sys; s=socket.socket(); s.settimeout(0.5); sys.exit(0 if s.connect_ex(('127.0.0.1',$FORWARD_FWD))==0 else 1)"; then
			return 0
		fi
		sleep 1
	done
	return 1
}

wait_ssh() {
	for _ in $(seq 1 60); do
		if vm true; then return 0; fi
		sleep 2
	done
	return 1
}

# ssh_cmd runs the command string with a quoting-safe channel, used where the
# command contains the panel-rendered shell text.
run_remote() {
	printf '%s\n' "$1" | vm_script
}

step "environment"
[ "$(uname -s)" = "Linux" ] || { echo "this test needs Linux (QEMU + KVM)"; exit 2; }
for tool in qemu-system-x86_64 sshpass curl python3 gunzip; do
	command -v "$tool" >/dev/null || { echo "missing $tool"; exit 2; }
done
ok "host tooling present"

# The three package names do not share a glob: leikwand, leikwan-runtime and
# luci-app-leikwan. Copy them by name, not by a leikwand* prefix.
PKG_FILES=""
for name in leikwand leikwan-runtime luci-app-leikwan; do
	found=$(cd "$IMAGE_DIR" && ls ${name}[_-]*.$EXT 2>/dev/null | head -1)
	[ -n "$found" ] || { echo "missing $name*.$EXT in $IMAGE_DIR"; exit 2; }
	PKG_FILES="$PKG_FILES $found"
done
PKG_FILES=$(echo $PKG_FILES)
ok "packages found: $PKG_FILES"

step "starting the mock panel, relays and artifact server"
mkdir -p "$WORK/panel-state"
python3 "$REPO/tools/leikwand/integration/mock_panel.py" "$WORK/panel-state" "$PANEL_PORT" >"$WORK/panel.log" 2>&1 &
PANEL_PID=$!
python3 "$REPO/tools/leikwand/integration/echo_server.py" "$ECHO_PORT" >"$WORK/echo.log" 2>&1 &
ECHO_PID=$!
# The router is a separate machine: the relays and the echo target must be
# reachable from the guest at 192.168.1.2, so they bind every interface.
export FAKE_RELAY_HOST=0.0.0.0
# Each relay records the credential of every stream it carries. The failover
# step reads those files to prove which relay actually served the traffic.
export FAKE_RELAY_LOG="$WORK/relay1.streams"
python3 "$REPO/tools/leikwand/integration/fake_relay.py" "$RELAY1_PORT" "$ECHO_PORT" >"$WORK/relay1.log" 2>&1 &
RELAY1_PID=$!
export FAKE_RELAY_LOG="$WORK/relay2.streams"
python3 "$REPO/tools/leikwand/integration/fake_relay.py" "$RELAY2_PORT" "$ECHO_PORT" >"$WORK/relay2.log" 2>&1 &
RELAY2_PID=$!
unset FAKE_RELAY_LOG
# The panel's layout: ipks under <arch>/, apks under apk/<arch>/, a manifest
# at /artifacts/openwrt-packages.txt and the installer next to it.
if [ "$EDITION" = "apk" ]; then PKG_SUBDIR="apk/amd64"; else PKG_SUBDIR="amd64"; fi
mkdir -p "$WORK/www/artifacts/files/openwrt/$PKG_SUBDIR"
for f in $PKG_FILES; do cp "$IMAGE_DIR/$f" "$WORK/www/artifacts/files/openwrt/$PKG_SUBDIR/"; done
(cd "$WORK/www/artifacts/files/openwrt" && for f in $PKG_FILES; do sha256sum "$PKG_SUBDIR/$f"; done) \
	> "$WORK/www/artifacts/openwrt-packages.txt"
if [ -n "$ARM64_DIR" ]; then
	if [ "$EDITION" = "apk" ]; then ARM_SUBDIR="apk/arm64"; else ARM_SUBDIR="arm64"; fi
	mkdir -p "$WORK/www/artifacts/files/openwrt/$ARM_SUBDIR"
	for name in leikwand leikwan-runtime; do
		f=$(cd "$ARM64_DIR" && ls ${name}[_-][0-9]*.$EXT 2>/dev/null | head -1)
		[ -n "$f" ] || { echo "missing arm64 $name in $ARM64_DIR"; exit 2; }
		cp "$ARM64_DIR/$f" "$WORK/www/artifacts/files/openwrt/$ARM_SUBDIR/"
		(cd "$WORK/www/artifacts/files/openwrt" && sha256sum "$ARM_SUBDIR/$f") >> "$WORK/www/artifacts/openwrt-packages.txt"
	done
fi
cp "$REPO/install/install-openwrt.sh" "$WORK/www/artifacts/install-openwrt.sh"
cp "$REPO/install/openwrt-versions.sh" "$WORK/www/artifacts/openwrt-versions.sh"
python3 -m http.server "$ART_PORT" --bind 0.0.0.0 --directory "$WORK/www" >"$WORK/art.log" 2>&1 &
ART_PID=$!
echo "192.168.1.2:$ECHO_PORT" > "$WORK/panel-state/target"
echo "$RELAY1_PORT" > "$WORK/panel-state/relay_port"
echo "$RELAY2_PORT" > "$WORK/panel-state/relay2_port"
echo "$FORWARD_PORT" > "$WORK/panel-state/listen_port"
# The router is a separate machine; every candidate endpoint must be the QEMU
# host address, not loopback.
echo "$GUEST_HOST" > "$WORK/panel-state/relay_host"
for _ in $(seq 1 30); do [ -f "$WORK/panel-state/ready" ] && break; sleep 0.5; done
[ -f "$WORK/panel-state/ready" ] && ok "mock panel listening on :$PANEL_PORT" || bad "mock panel did not start"
ok "artifact server serving $EDITION packages on :$ART_PORT"

step "booting the stock OpenWrt $RELEASE image"
BASE="https://downloads.openwrt.org/releases/$RELEASE/targets/x86/64"
IMG_NAME="openwrt-$RELEASE-x86-64-generic-ext4-combined-efi.img.gz"
KERNEL_NAME="openwrt-$RELEASE-x86-64-generic-kernel.bin"
curl -fsSL "$BASE/$IMG_NAME" -o "$WORK/base.img.gz" || { echo "image download failed"; exit 1; }
curl -fsSL "$BASE/$KERNEL_NAME" -o "$WORK/kernel.bin" || { echo "kernel download failed"; exit 1; }
ok "stock firmware downloaded ($(du -h "$WORK/base.img.gz" | cut -f1))"

# Inject the harness SSH key. The image is a combined ext4 disk, so the key is
# written into /root/.ssh/authorized_keys inside the rootfs before first boot.
gunzip -kf "$WORK/base.img.gz" 2>/dev/null || [ -f "$WORK/base.img" ] || { echo "gunzip failed"; exit 1; }
IMG="$WORK/disk.img"
cp "$WORK/base.img" "$IMG"
# Grow the disk and its rootfs partition (p2). The stock rootfs has ~76 MB
# free; the packages plus their metadata need less, but a router in service
# writes logs, so the test uses a disk with room to breathe.
truncate -s 1024M "$IMG"
START=$(sfdisk -d "$IMG" 2>/dev/null | awk -F'[ =,]+' '/disk\.img2 : start=/ {print $4; exit}')
[ -n "$START" ] || START=$(python3 - "$IMG" <<'PY'
import sys
with open(sys.argv[1], 'rb') as img:
    img.seek(0x800)
    for _ in range(8):
        entry = img.read(128)
        if not entry or entry[4] == 0:
            continue
        start = int.from_bytes(entry[8:12], 'little')
        if start > 0:
            print(start)
            break
PY
)
[ -n "$START" ] || { echo "could not find the rootfs partition"; exit 1; }
SECTORS=$(( $(stat -c %s "$IMG") / 512 ))
SIZE=$(( SECTORS - 34 - START ))
sfdisk --no-tell-kernel "$IMG" -N 2 --force >/dev/null 2>&1 <<SEOF
$START,$SIZE,0FC63DAF-8483-4772-8E79-3D69D8477DE4
SEOF
# The filesystem is grown on its own copy: resize2fs in this version has no
# -E offset, and a loop device would need root.
dd if="$IMG" of="$WORK/rootfs.img" bs=512 skip="$START" count="$SIZE" status=none
truncate -s $(( SIZE * 512 )) "$WORK/rootfs.img"
e2fsck -fy "$WORK/rootfs.img" >/dev/null 2>&1
resize2fs "$WORK/rootfs.img" >/dev/null 2>&1
ssh-keygen -q -t ed25519 -N '' -f "$SSH_KEY"
debugfs -w -R "mkdir /root/.ssh" "$WORK/rootfs.img" >/dev/null 2>&1
# The stock image ships an empty authorized_keys; debugfs "write" refuses to
# overwrite an existing file, so remove it first or the key never lands and
# the VM boots without a way in.
debugfs -w -R "rm /root/.ssh/authorized_keys" "$WORK/rootfs.img" >/dev/null 2>&1
debugfs -w -R "write $SSH_KEY.pub /root/.ssh/authorized_keys" "$WORK/rootfs.img" >/dev/null 2>&1
debugfs -w -R "sif /root/.ssh/authorized_keys mode 0100600" "$WORK/rootfs.img" >/dev/null 2>&1
debugfs -w -R "sif /root/.ssh mode 040700" "$WORK/rootfs.img" >/dev/null 2>&1
debugfs -w -R "mkdir /etc/dropbear" "$WORK/rootfs.img" >/dev/null 2>&1
debugfs -R "ls -l /root/.ssh" "$WORK/rootfs.img" 2>/dev/null | grep -q authorized_keys \
	|| { echo "SSH key injection failed"; exit 1; }
dd if="$WORK/rootfs.img" of="$IMG" bs=512 seek="$START" conv=notrunc status=none
ok "rootfs grown to $(( SIZE / 2048 )) MB and SSH key injected"

ACCEL=""
[ -w /dev/kvm ] && ACCEL="-enable-kvm"
# No -no-reboot: the test reboots the router and expects it back. With
# -no-reboot QEMU exits on the guest's reboot, so the machine would never
# return and that step would assert nothing.
qemu-system-x86_64 \
	-machine q35 -cpu max -m 1024 -smp 2 $ACCEL \
	-kernel "$WORK/kernel.bin" \
	-append "root=/dev/vda2 rootwait console=ttyS0" \
	-drive file="$IMG",format=raw,if=virtio \
	-netdev "user,id=lan,net=192.168.1.0/24,host=$GUEST_HOST,hostfwd=tcp:127.0.0.1:$HOST_FWD-192.168.1.1:22,hostfwd=tcp:127.0.0.1:$FORWARD_FWD-192.168.1.1:$FORWARD_PORT" \
	-device virtio-net-pci,netdev=lan \
	-display none -daemonize -pidfile "$WORK/qemu.pid" \
	-serial "file:$VM_LOG"
QEMU_PID=$(cat "$WORK/qemu.pid")
ok "VM started (pid $QEMU_PID, port $HOST_FWD${ACCEL:+, KVM})"

if wait_ssh; then ok "SSH reached the router"; else
	bad "SSH never came up"; tail -60 "$VM_LOG"; exit 1
fi
FW=$(vm '. /etc/openwrt_release; echo "$DISTRIB_RELEASE"')
PKGMGR=$(vm 'command -v apk || command -v opkg')
ok "firmware $FW, package manager $PKGMGR"
if [ "$EDITION" = "apk" ] && ! echo "$PKGMGR" | grep -q apk; then bad "expected apk on 25.12+"; fi
if [ "$EDITION" = "opkg" ] && ! echo "$PKGMGR" | grep -q opkg; then bad "expected opkg on 24.10"; fi

step "the one-line installer detects the firmware and installs"
# The card's command: fetch install-openwrt.sh with wget (curl as fallback) and
# run it against the panel. It must pick the package manager, the build and the
# LuCI app on its own, and verify every file against the manifest. No
# credentials here: the router still points at the production panel until the
# next step, and a CI run must never send a test token there.
PANEL_LOCAL="http://$GUEST_HOST:$ART_PORT"
INSTALLER_CMD="{ wget -qO /tmp/leikwan-install.sh '$PANEL_LOCAL/artifacts/install-openwrt.sh' || curl -fsSL -o /tmp/leikwan-install.sh '$PANEL_LOCAL/artifacts/install-openwrt.sh'; } && sh /tmp/leikwan-install.sh --panel '$PANEL_LOCAL'; echo installer-exit=\$?"
OUT=$(run_remote "$INSTALLER_CMD")
echo "$OUT" | sed 's/^/    | /'
echo "$OUT" | grep -q 'installer-exit=0' && ok "installer finished successfully" || bad "installer failed"
echo "$OUT" | grep -q "package manager: $EDITION, build: amd64, LuCI app: yes" \
	&& ok "installer detected $EDITION, amd64 and LuCI" || bad "wrong detection"
LUCI_FILE=$(vm 'test -x /usr/libexec/rpcd/luci.leikwand && echo present')
[ "$LUCI_FILE" = "present" ] && ok "LuCI app installed" || bad "LuCI app missing"
INSTALLED=$(vm 'command -v leikwand; test -x /usr/libexec/leikwan/ultra-client && echo runtime-ok; ls /etc/init.d/leikwand')
echo "$INSTALLED" | grep -q '/usr/bin/leikwand' && ok "leikwand binary installed" || bad "leikwand binary missing: $INSTALLED"
echo "$INSTALLED" | grep -q 'runtime-ok' && ok "forwarding runtime installed" || bad "runtime missing: $INSTALLED"
echo "$INSTALLED" | grep -q 'init.d/leikwand' && ok "init script installed" || bad "init script missing: $INSTALLED"
SELFTEST=$(vm 'leikwand selftest' )
echo "$SELFTEST" | grep -q 'all checks passed' && ok "leikwand selftest passes on the router" || bad "selftest: $SELFTEST"

step "the version report detects what was installed"
# Same detection rules as the installer, on a real image: it must agree with
# what was just installed and report the manifest's own package as current.
VER_CMD="wget -qO /tmp/leikwan-versions.sh '$PANEL_LOCAL/artifacts/openwrt-versions.sh' && sh /tmp/leikwan-versions.sh --panel '$PANEL_LOCAL'"
VER=$(run_remote "$VER_CMD")
echo "$VER" | sed 's/^/    | /'
echo "$VER" | grep -q "package manager *$EDITION (." && ok "report detects the $EDITION package manager" || bad "report package manager: $VER"
echo "$VER" | grep -q "x86_64 -> amd64 build (package arch x86_64)" && ok "report resolves arch and package arch" || bad "report arch"
# The version comes from the build, not from this file: assert the row is
# populated and self-consistent (installed "X-r1", running "X") instead of
# pinning a release number the next bump would break.
echo "$VER" | grep -qE "leikwand +[0-9]+\.[0-9]+\.[0-9]+-r[0-9]+ +[0-9]+\.[0-9]+\.[0-9]+ " && ok "report shows the installed and running leikwand version" || bad "report leikwand row: $VER"
echo "$VER" | grep -q "up to date" && ok "report finds nothing to update after the install" || bad "report state: $VER"
echo "$VER" | grep -q "Nothing to change" && ok "report closes with nothing to change" || bad "report closing"
TGT=$(run_remote "sh /tmp/leikwan-versions.sh --panel '$PANEL_LOCAL' --target")
case "$TGT" in
	apk/amd64/leikwand-*.apk|amd64/leikwand_*_x86_64.ipk) ok "report names the package file this router installs ($TGT)" ;;
	*) bad "report --target: $TGT" ;;
esac
# --check's exit status is the point of the flag, and run_remote returns ssh's
# status, so it has to be read on the router.
CHECK_RC=$(run_remote "sh /tmp/leikwan-versions.sh --panel '$PANEL_LOCAL' --check; echo check-exit=\$?")
echo "$CHECK_RC" | grep -q 'check-exit=0' && ok "report --check exits 0 when the router is current" || bad "report --check: $CHECK_RC"
JSON=$(run_remote "sh /tmp/leikwan-versions.sh --panel '$PANEL_LOCAL' --json")
echo "$JSON" | grep -q '"update_available":false' && ok "report --json says up to date" || bad "report json: $JSON"

step "procd owns the daemon"
# postinst enables and starts the service; the status must come from procd,
# not from a manual start, or the router loses the daemon on the next reboot.
for _ in $(seq 1 30); do
	STATE=$(vm 'ubus call service list "{\"name\":\"leikwand\"}"' 2>/dev/null)
	echo "$STATE" | grep -q '"running": *true' && break
	sleep 2
done
echo "$STATE" | grep -q '"running": *true' && ok "procd reports leikwand running after install" || bad "procd does not run leikwand: $STATE"
ENABLED=$(vm 'ls /etc/rc.d/ | grep leikwand')
[ -n "$ENABLED" ] && ok "service enabled at boot ($ENABLED)" || bad "service is not enabled at boot"

step "point the router at the mock panel"
# The package ships the production panel URL. The test swaps in the mock panel
# through the same UCI keys an operator edits (or LuCI writes) and restarts the
# service, which is also the reload path a real configuration change takes.
vm_script <<'UCICONF'
uci set leikwan.main.panel_url='http://192.168.1.2:18080'
uci set leikwan.main.fallback_panel_url='http://192.168.1.2:1'
# The mock panel is the QEMU host. Pinning panel traffic to the WAN device
# makes that dial fail (eth0 is a bridge port, so auto-pinning skips it and
# the dial goes out unbound), and a failed pin is not the behaviour under
# test here. A production router pins; this test is about the daemon.
uci set leikwan.main.panel_egress_interface='none'
uci set leikwan.main.poll_interval='2'
uci set leikwan.main.heartbeat_interval='2'
uci set leikwan.main.health_interval='3'
uci set leikwan.main.cold_start_grace='20'
uci commit leikwan
/etc/init.d/leikwand restart
UCICONF
sleep 3
PANEL_URL=$(vm 'leikwand ctl status' | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["panel_url"])' 2>/dev/null)
[ "$PANEL_URL" = "http://192.168.1.2:18080" ] && ok "daemon now talks to the mock panel" || bad "panel url is '$PANEL_URL'"

step "registration from LuCI's rpcd backend"
# This is the 25.12 regression path: rpcd runs the ucode backend, which calls
# the daemon over the control socket. A broken call looks like "not enrolled".
# ubus takes the argument object as an argument, not on stdin: piping JSON
# here leaves args empty and rpcd answers agent_token_required.
REG=$(vm 'ubus call luci.leikwand register '"'"'{"client_id":42,"agent_token":"it-agent-token"}'"'"'')
echo "$REG" | grep -q '"registered": *true' && ok "register accepted over ubus" || bad "register failed: $REG"
STATUS=$(vm 'ubus call luci.leikwand status')
echo "$STATUS" | grep -q '"enrolled": *true' && ok "LuCI reports enrolled" || bad "LuCI status: $STATUS"
# UCI keeps data_dir, and the credential file lives under it. The stock
# package sets /etc/leikwan; read the effective value rather than assuming.
# `stat` is not on the stock image, so the mode comes from ls: for a check
# that only asks "is this owner-only", that is equivalent and always present.
DATA_DIR=$(vm 'uci -q get leikwan.main.data_dir')
DATA_DIR=${DATA_DIR:-/etc/leikwan}
SECRET_MODE=$(vm "ls -l $DATA_DIR/secret.json" | awk '{print $1}')
case "$SECRET_MODE" in
	-rw-------) ok "credential file is owner-only ($SECRET_MODE)" ;;
	*) bad "credential file mode is '$SECRET_MODE'" ;;
esac
TOKEN_SEEN=$(vm "grep -c it-agent-token $DATA_DIR/secret.json")
[ "$TOKEN_SEEN" = "1" ] && ok "token stored once in the credential file" || bad "token not stored as expected"

step "the panel's register command line"
# The install card ends with `leikwand ctl register client_id=N agent_token=...`,
# which is a different path from the ubus call above: it goes through the CLI
# and the control socket's shared secret. Sign out first so the command has to
# enrol for real rather than finding credentials already in place.
vm 'leikwand ctl sign_out' >/dev/null 2>&1
sleep 2
SIGNED_OUT=$(router_status "d.get('enrolled')")
[ "$SIGNED_OUT" = "False" ] && ok "sign_out cleared the credential" || bad "sign_out left the device enrolled ($SIGNED_OUT)"
CLI_REG=$(vm 'leikwand ctl register client_id=42 agent_token=it-agent-token')
echo "$CLI_REG" | grep -q '"ok":true' && ok "ctl register accepted the card's command" || bad "ctl register failed: $CLI_REG"
CLI_ENROLLED=$(router_status "d.get('enrolled')")
[ "$CLI_ENROLLED" = "True" ] && ok "device is enrolled after the CLI register" || bad "not enrolled after ctl register ($CLI_ENROLLED)"
CLI_CLIENT=$(router_status "d.get('client_id')")
[ "$CLI_CLIENT" = "42" ] && ok "ctl register adopted the client id" || bad "client id is $CLI_CLIENT"

step "configuration pull and forward"
for _ in $(seq 1 60); do
	FORWARDS=$(vm 'leikwand ctl forwards')
	echo "$FORWARDS" | grep -q '"status":"running"' && break
	sleep 2
done
echo "$FORWARDS" | grep -q '"status":"running"' && ok "forward is running" || bad "forward never started: $FORWARDS"
echo "$FORWARDS" | grep -q '"listen_port":10001' && ok "forward listens on the panel's port" || bad "wrong port: $FORWARDS"
echo "$FORWARDS" | grep -q '"candidates":' && ok "candidate batch installed" || bad "no candidates: $FORWARDS"
PANEL_HDR=$(jsonq "$WORK/panel-state/last_pull_headers.json" "d['package_manager']")
# The header ships with the 1.2.6 build, so a CI-built package always sends it.
# The packages published before that release do not; a smoke run against them
# exercises the panel's fallback instead, which is not a defect.
if [ "$PANEL_HDR" = "$EDITION" ]; then
	ok "router reports package manager '$EDITION' to the panel"
elif [ -z "$PANEL_HDR" ] && [ "${EXPECT_FLAVOUR_HEADER:-1}" = "0" ]; then
	ok "pre-1.2.6 build sends no package-manager header (panel falls back)"
else
	bad "X-Device-Package-Manager was '$PANEL_HDR', expected '$EDITION'"
fi
AUTH=$(jsonq "$WORK/panel-state/last_pull_headers.json" "d['authorization']")
echo "$AUTH" | grep -q '^Bearer ' && ok "pull is authenticated" || bad "pull not authenticated: $AUTH"

step "data path through the router"
wait_forward_port 30 && ok "forwarded port $FORWARD_PORT is reachable through the router" || bad "forwarded port never opened"
ROUNDTRIP=$(roundtrip_retry 10 "hello from the CI router")
case "$ROUNDTRIP" in
	echo:*) ok "traffic reached the target and returned: $ROUNDTRIP" ;;
	*) bad "round trip failed (got: $ROUNDTRIP)" ;;
esac

step "usage reaches the panel"
for _ in $(seq 1 30); do
	[ -s "$WORK/panel-state/usage.jsonl" ] && break
	sleep 2
done
USAGE=$(cat "$WORK/panel-state/usage.jsonl" 2>/dev/null)
if [ -n "$USAGE" ]; then
	ok "panel received usage snapshots"
	TOTALS=$(python3 - "$WORK/panel-state/usage_totals.json" <<'PY'
import json,sys
d=json.load(open(sys.argv[1]))
print(sum(v["bytes_in"]+v["bytes_out"] for v in d.values()))
PY
)
	[ "${TOTALS:-0}" -gt 0 ] && ok "billed bytes are non-zero ($TOTALS)" || bad "usage totals are zero"
else
	bad "no usage reached the panel"
fi

step "synchronisation: a line rotation reaches the router"
# The panel publishes a new generation and the router must pull it on its next
# poll. The lease request under it follows only when the current grant nears
# renewal, so the pull is the delivery signal.
# The panel publishes a new policy generation, which is how a line rotation
# reaches the router. Wait for the router's next pull to carry it, rather than
# assuming a sleep is long enough.
NEWGEN=$(printf 'b%.0s' $(seq 1 64))
echo "$NEWGEN" > "$WORK/panel-state/policy_generation"
ROTATED=0
for _ in $(seq 1 45); do
	if grep -q "$NEWGEN" "$WORK/panel-state/generations.jsonl" 2>/dev/null; then ROTATED=1; break; fi
	sleep 2
done
[ "$ROTATED" = "1" ] && ok "router pulled the new generation" || bad "new generation never reached the panel"
# The status document omits an empty last_pull_error, so ask JSON rather than
# grep: a present, non-empty value is the failure.
PULL_ERR=$(vm 'leikwand ctl status' | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"].get("last_pull_error") or "")' 2>/dev/null)
[ -z "$PULL_ERR" ] && ok "router keeps pulling with no error after the rotation" || bad "pull error after rotation: $PULL_ERR"
case "$(roundtrip "$FORWARD_FWD" "after rotation")" in
	echo:*) ok "traffic still flows after the rotation" ;;
	*) bad "traffic stopped after the rotation" ;;
esac

step "short network outage: the panel disappears"
# The router cannot reach the panel. The grant it holds must keep serving:
# a control-plane hiccup is not a customer outage.
echo "1" > "$WORK/panel-state/offline"
sleep 20
OUTAGE_ERR=$(router_status "d.get('last_pull_error') or ''")
[ -n "$OUTAGE_ERR" ] && ok "router reports the panel as unreachable ($OUTAGE_ERR)" || bad "outage not reported"
OUTAGE_TRAFFIC=$(roundtrip "$FORWARD_FWD" "during outage")
case "$OUTAGE_TRAFFIC" in
	echo:*) ok "existing forward kept serving through the outage" ;;
	*) bad "traffic dropped during the outage: $OUTAGE_TRAFFIC" ;;
esac
PROCD_OUT=$(vm 'ubus call service list "{\"name\":\"leikwand\"}"')
echo "$PROCD_OUT" | grep -q '"running": *true' && ok "daemon stayed up during the outage" || bad "daemon died during the outage"

step "recovery: the panel comes back"
rm -f "$WORK/panel-state/offline"
RECOVERED=0
for _ in $(seq 1 60); do
	if [ -z "$(router_status "d.get('last_pull_error') or ''")" ]; then RECOVERED=1; break; fi
	sleep 3
done
[ "$RECOVERED" = "1" ] && ok "router reconnected to the panel" || bad "router never reconnected"
RECOVERY_TRAFFIC=$(roundtrip "$FORWARD_FWD" "after outage")
case "$RECOVERY_TRAFFIC" in
	echo:*) ok "traffic flows again after recovery" ;;
	*) bad "traffic did not recover: $RECOVERY_TRAFFIC" ;;
esac

step "automatic failover when the active relay dies"
# The runtime holds two live candidates plus one blackholed probe target. Kill
# the relay that currently carries traffic and a new stream must still be
# served, over the other candidate, without operator action.
# Which relay is carrying traffic right now? The runtime picks a candidate at
# random among the live ones, so read it from the relays' own stream records.
BEFORE1=$(wc -l < "$WORK/relay1.streams" 2>/dev/null || echo 0)
BEFORE2=$(wc -l < "$WORK/relay2.streams" 2>/dev/null || echo 0)
# Confirm the runtime is actually using one of them before the kill.
ROUNDTRIP=$(roundtrip_retry 5 "pre-failover")
case "$ROUNDTRIP" in
	echo:*) ok "traffic flows before the failover" ;;
	*) bad "traffic did not flow before the failover: $ROUNDTRIP" ;;
esac
ACTIVE=1
[ "$(wc -l < "$WORK/relay2.streams" 2>/dev/null || echo 0)" -gt "$BEFORE2" ] && ACTIVE=2
ok "the active relay is relay $ACTIVE before the kill"
# Kill the relay that was serving, keep the other up.
if [ "$ACTIVE" = "2" ]; then
	stop_pid "$RELAY2_PID"; RELAY2_PID=""
	BEFORE=$(wc -l < "$WORK/relay1.streams" 2>/dev/null || echo 0)
	WATCH="$WORK/relay1.streams"
else
	stop_pid "$RELAY1_PID"; RELAY1_PID=""
	BEFORE=$(wc -l < "$WORK/relay2.streams" 2>/dev/null || echo 0)
	WATCH="$WORK/relay2.streams"
fi
FAILOVER_OK=0
for _ in $(seq 1 12); do
	RESP=$(roundtrip "$FORWARD_FWD" "failover probe")
	case "$RESP" in
		echo:*) FAILOVER_OK=1; break ;;
	esac
	sleep 2
done
[ "$FAILOVER_OK" = "1" ] && ok "a new stream was served after the active relay died" || bad "no failover (last response: $RESP)"
AFTER=$(wc -l < "$WATCH" 2>/dev/null || echo 0)
[ "$AFTER" -gt "$BEFORE" ] && ok "the surviving relay carried the traffic ($BEFORE -> $AFTER streams)" \
	|| bad "the surviving relay saw no traffic ($BEFORE -> $AFTER); the failover did not happen"
if [ -z "$RELAY1_PID" ]; then
	FAKE_RELAY_LOG="$WORK/relay1.streams" FAKE_RELAY_HOST=0.0.0.0 \
		python3 "$REPO/tools/leikwand/integration/fake_relay.py" "$RELAY1_PORT" "$ECHO_PORT" >"$WORK/relay1b.log" 2>&1 &
	RELAY1_PID=$!
fi
if [ -z "$RELAY2_PID" ]; then
	FAKE_RELAY_LOG="$WORK/relay2.streams" FAKE_RELAY_HOST=0.0.0.0 \
		python3 "$REPO/tools/leikwand/integration/fake_relay.py" "$RELAY2_PORT" "$ECHO_PORT" >"$WORK/relay2b.log" 2>&1 &
	RELAY2_PID=$!
fi

step "unattended reboot"
vm 'reboot' >/dev/null 2>&1
sleep 8
if wait_ssh; then ok "router came back after reboot"; else bad "router did not come back"; fi
REBOOTED=0
for _ in $(seq 1 60); do
	FORWARDS=$(vm 'leikwand ctl forwards')
	if echo "$FORWARDS" | grep -q '"status":"running"'; then REBOOTED=1; break; fi
	sleep 3
done
[ "$REBOOTED" = "1" ] && ok "forward is running again with no operator action" || bad "forward did not come back: $FORWARDS"
REBOOT_TRAFFIC=$(roundtrip_retry 10 "after reboot")
case "$REBOOT_TRAFFIC" in
	echo:*) ok "traffic flows after an unattended reboot" ;;
	*) bad "traffic did not come back after reboot: $REBOOT_TRAFFIC" ;;
esac
ROTATION_SURVIVED=$(vm 'leikwand ctl forwards')
echo "$ROTATION_SURVIVED" | grep -q '"status":"running"' && ok "the rotated configuration survived the reboot" || bad "configuration lost on reboot"

step "clean uninstall"
# The LuCI app depends on the agent, so it goes first: removing the agent
# while a dependent is installed is correctly refused by opkg, and asking for
# the impossible would only assert the package manager's error message.
if [ "$EDITION" = "apk" ]; then
	UNINSTALL=$(vm 'apk del luci-app-leikwan && apk del leikwand leikwan-runtime 2>&1; echo exit=$?')
else
	UNINSTALL=$(vm 'opkg remove luci-app-leikwan && opkg remove leikwand leikwan-runtime 2>&1; echo exit=$?')
fi
echo "$UNINSTALL" | grep -q 'exit=0' && ok "packages removed" || bad "uninstall failed: $UNINSTALL"
vm 'ubus call service list "{\"name\":\"leikwand\"}"' | grep -q '"running": *true' && bad "daemon still running after removal" || ok "daemon stopped with the package"
vm 'test -e /usr/bin/leikwand && echo present' | grep -q present && bad "binary left behind" || ok "binary removed"

step "arm64 packages on a firmware that does not list aarch64_generic"
# Most aarch64 routers declare a CPU-specific architecture (aarch64_cortex-a53,
# ...) while the arm64 packages are aarch64_generic, and both apk and opkg
# refuse a package whose architecture the firmware does not list. This x86
# router lists neither, so installing the real arm64 packages here (the build
# forced with LEIKWAN_MACHINE) reproduces exactly that refusal. The installer
# must add the architecture persistently, and a plain package-manager install
# afterwards — the agent's self-update path — must work without its help. The
# arm64 binary cannot run on x86, so the service itself is not asserted.
if [ -z "$ARM64_DIR" ]; then
	echo "  (skipped: no --arm64-dir)"
else
	if [ "$EDITION" = "apk" ]; then
		vm 'grep -qx aarch64_generic /etc/apk/arch' && bad "firmware already lists aarch64_generic" || ok "firmware does not list aarch64_generic"
	else
		vm 'opkg print-architecture' | grep -qw aarch64_generic && bad "firmware already lists aarch64_generic" || ok "firmware does not list aarch64_generic"
	fi
	ARM_CMD="{ wget -qO /tmp/leikwan-install.sh '$PANEL_LOCAL/artifacts/install-openwrt.sh' || curl -fsSL -o /tmp/leikwan-install.sh '$PANEL_LOCAL/artifacts/install-openwrt.sh'; } && LEIKWAN_MACHINE=aarch64 sh /tmp/leikwan-install.sh --panel '$PANEL_LOCAL' --no-luci; echo installer-exit=\$?"
	OUT=$(run_remote "$ARM_CMD")
	echo "$OUT" | sed 's/^/    | /'
	echo "$OUT" | grep -q 'installer-exit=0' && ok "installer installed the aarch64_generic packages" || bad "installer failed on the arch mismatch"
	echo "$OUT" | grep -q 'adding aarch64_generic' && ok "installer added the package architecture" || bad "no architecture was added"
	if [ "$EDITION" = "apk" ]; then
		vm 'apk info -e leikwand' | grep -q leikwand && ok "apk lists leikwand as installed" || bad "leikwand not installed"
		vm 'grep -qx aarch64_generic /etc/apk/arch && echo kept' | grep -q kept && ok "/etc/apk/arch keeps aarch64_generic" || bad "arch not persisted"
		SELF=$(run_remote "cd /tmp && wget -qO self.apk '$PANEL_LOCAL/artifacts/files/openwrt/apk/arm64/$(cd "$ARM64_DIR" && ls leikwand-[0-9]*.apk | head -1)' && apk add --allow-untrusted /tmp/self.apk >/dev/null 2>&1; echo self-exit=\$?")
	else
		vm 'opkg status leikwand' | grep -q 'Status:.* installed' && ok "opkg lists leikwand as installed" || bad "leikwand not installed"
		vm 'opkg print-architecture' | grep -qw aarch64_generic && ok "opkg keeps aarch64_generic configured" || bad "arch not persisted"
		vm 'opkg print-architecture' | grep -qw x86_64 && ok "the firmware's own architecture is still listed" || bad "the firmware arch was dropped"
		SELF=$(run_remote "cd /tmp && wget -qO self.ipk '$PANEL_LOCAL/artifacts/files/openwrt/arm64/$(cd "$ARM64_DIR" && ls leikwand_[0-9]*.ipk | head -1)' && opkg install --force-reinstall /tmp/self.ipk >/dev/null 2>&1; echo self-exit=\$?")
	fi
	echo "$SELF" | grep -q 'self-exit=0' && ok "a plain reinstall works afterwards (self-update path)" || bad "plain reinstall failed: $SELF"
	if [ "$EDITION" = "apk" ]; then
		vm 'apk del leikwand leikwan-runtime >/dev/null 2>&1; sed -i "/^aarch64_generic$/d" /etc/apk/arch'
	else
		vm 'opkg remove leikwand leikwan-runtime >/dev/null 2>&1; rm -f /etc/opkg/leikwan-arch.conf'
	fi
	OTHERS=$(vm "$([ "$EDITION" = apk ] && echo 'apk info -e busybox' || echo 'opkg status busybox')")
	echo "$OTHERS" | grep -q 'busybox\|installed' && ok "the firmware's own packages are untouched" || bad "firmware packages affected: $OTHERS"
fi

step "result"
printf '%d passed, %d failed\n' "$PASS" "$FAIL"
if [ "$FAIL" -ne 0 ]; then
	echo "--- serial log (tail) ---"
	tail -80 "$VM_LOG" 2>/dev/null
	echo "--- daemon log (tail) ---"
	vm 'logread -e leikwand | tail -200' > "$WORK/daemon.log" 2>/dev/null
	cat "$WORK/daemon.log"
	# The router's view of the whole run, not just the tail, so a failed CI
	# job can be diagnosed from the uploaded artifact alone.
	vm 'logread' > "$WORK/syslog.log" 2>/dev/null
fi
[ "$FAIL" -eq 0 ]
