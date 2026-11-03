#!/usr/bin/env bash
# Unit test for install/install-openwrt.sh.
#
# The installer runs under plain `sh` against a scratch root with stub apk /
# opkg / leikwand commands, so every detection branch is exercised without a
# router: package manager, CPU family, LuCI decision, the architecture override,
# checksum verification, registration and the refusal paths. The router VM
# suite (openwrt/tests/router-vm-e2e.sh) runs the same script on real images.
set -uo pipefail
cd "$(dirname "$0")/../.."
SCRIPT="$PWD/install/install-openwrt.sh"
T=$(mktemp -d /tmp/leikwan-installer-test-XXXXXX)
trap 'rm -rf "$T"' EXIT
PASS=0
FAIL=0
ok()  { PASS=$((PASS+1)); echo "  PASS $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }

# --- a fake panel: packages plus a manifest --------------------------------
PANEL="$T/panel"
FILES_DIR="$PANEL/artifacts/files/openwrt"
mkdir -p "$FILES_DIR"/{amd64,arm64,apk/amd64,apk/arm64}
for path in \
	amd64/leikwand_1.2.6-r1_x86_64.ipk amd64/leikwan-runtime_1.2.6-r1_x86_64.ipk amd64/luci-app-leikwan_1.1.1-r1_all.ipk \
	arm64/leikwand_1.2.6-r1_aarch64_generic.ipk arm64/leikwan-runtime_1.2.6-r1_aarch64_generic.ipk arm64/luci-app-leikwan_1.1.1-r1_all.ipk \
	amd64/leikwand_1.2.5-r1_x86_64.ipk \
	apk/amd64/leikwand-1.2.6-r1.apk apk/amd64/leikwan-runtime-1.2.6-r1.apk apk/amd64/luci-app-leikwan-1.1.1-r1.apk \
	apk/arm64/leikwand-1.2.6-r1.apk apk/arm64/leikwan-runtime-1.2.6-r1.apk apk/arm64/luci-app-leikwan-1.1.1-r1.apk; do
	echo "payload $path" > "$FILES_DIR/$path"
done
make_manifest() { # the 1.2.5 file is on disk but not in the manifest
	{
		echo "# test manifest"
		(cd "$FILES_DIR" && find . -type f ! -name '*1.2.5*' | sed 's#^\./##' | sort | xargs sha256sum)
	} > "$PANEL/artifacts/openwrt-packages.txt"
}
make_manifest

# --- stub commands ---------------------------------------------------------
# new_root <name> <pm> : a scratch router root and a PATH holding only the stubs
# that firmware would have.
new_root() {
	ROOT="$T/$1"; BIN="$ROOT/stubbin"; CALLS="$ROOT/calls"
	mkdir -p "$ROOT/etc/init.d" "$ROOT/var/run/leikwand" "$BIN"
	: > "$CALLS"
	echo "DISTRIB_ID='OpenWrt'"$'\n'"DISTRIB_RELEASE='test'"$'\n'"DISTRIB_ARCH='test_arch'" > "$ROOT/etc/openwrt_release"
	touch "$ROOT/var/run/leikwand/ctl.sock"
	cat > "$ROOT/etc/init.d/leikwand" <<EOF
#!/bin/sh
echo "init \$*" >> "$CALLS"
exit 0
EOF
	cat > "$BIN/leikwand" <<EOF
#!/bin/sh
echo "leikwand \$*" >> "$CALLS"
case "\$1" in
	version) echo "leikwand 1.2.6" ;;
	ctl) echo '{"ok":true,"data":{"client_id":7}}' ;;
esac
EOF
	if [ "$2" = apk ]; then
		mkdir -p "$ROOT/etc/apk"; : > "$ROOT/etc/apk/world"
		cat > "$BIN/apk" <<EOF
#!/bin/sh
echo "apk \$*" >> "$CALLS"
case "\$*" in
	"info -e luci-base") [ -n "\${STUB_LUCI_BASE:-}" ] ;;
	*) exit 0 ;;
esac
EOF
	else
		mkdir -p "$ROOT/usr/lib/opkg"
		cat > "$BIN/opkg" <<EOF
#!/bin/sh
echo "opkg \$*" >> "$CALLS"
case "\$1" in
	status) [ -n "\${STUB_LUCI_BASE:-}" ] && echo "Status: install user installed" ;;
	print-architecture) for a in \${STUB_ARCHES:-all noarch}; do echo "arch \$a 10"; done ;;
esac
exit 0
EOF
	fi
	chmod +x "$BIN"/* "$ROOT/etc/init.d/leikwand"
}

run() { # run <machine> [args...]  -> output in $OUT, status in $RC
	local machine="$1"; shift
	OUT=$(env -i PATH="$BIN:/usr/bin:/bin" HOME="$T" LEIKWAN_ROOT="$ROOT" LEIKWAN_MACHINE="$machine" \
		LEIKWAN_REGISTER_WAIT=2 STUB_LUCI_BASE="${STUB_LUCI_BASE:-}" STUB_ARCHES="${STUB_ARCHES:-}" \
		sh "$SCRIPT" --panel "file://$PANEL" "$@" 2>&1)
	RC=$?
}
called() { grep -q -- "$1" "$CALLS"; }

echo "== apk, x86_64, LuCI present, register"
new_root apk-x86 apk
echo x86_64 > "$ROOT/etc/apk/arch"
ln -s /bin/true "$BIN/ucode"
STUB_LUCI_BASE=1 run x86_64 --client-id 7 --agent-token 'tok 1'
[ "$RC" = 0 ] && ok "exit 0" || bad "exit $RC: $OUT"
called "apk add --allow-untrusted .*/leikwand-1.2.6-r1.apk .*/leikwan-runtime-1.2.6-r1.apk .*/luci-app-leikwan-1.1.1-r1.apk" \
	&& ok "apk installs the three amd64 apks" || bad "apk call: $(cat "$CALLS")"
called "--arch" && bad "no architecture override when the arch matches" || ok "no architecture override when the arch matches"
called "leikwand ctl register client_id=7 agent_token=tok 1" && ok "registers with the given credentials" || bad "register: $(cat "$CALLS")"
echo "$OUT" | grep -q "LuCI app: yes" && ok "reports LuCI" || bad "LuCI not reported: $OUT"

echo "== opkg, aarch64, no ucode (21.02/22.03 style), CPU-specific arch"
new_root opkg-arm opkg
STUB_ARCHES="all noarch aarch64_cortex-a53" run aarch64
[ "$RC" = 0 ] && ok "exit 0" || bad "exit $RC: $OUT"
called "opkg install .*/leikwand_1.2.6-r1_aarch64_generic.ipk .*/leikwan-runtime_1.2.6-r1_aarch64_generic.ipk$" \
	&& ok "opkg installs the arm64 core ipks" || bad "opkg call: $(cat "$CALLS")"
called "add-arch" && bad "never --add-arch: it replaces the firmware's list" || ok "no --add-arch"
CONF="$ROOT/etc/opkg/leikwan-arch.conf"
grep -qx "arch aarch64_cortex-a53 10" "$CONF" 2>/dev/null && grep -qx "arch all 10" "$CONF" && grep -qx "arch aarch64_generic 5" "$CONF" \
	&& ok "arch.conf keeps the firmware's list and adds aarch64_generic" || bad "arch conf: $(cat "$CONF" 2>/dev/null)"
called "luci-app-leikwan" && bad "no LuCI app without ucode" || ok "no LuCI app without ucode"
called "ctl register" && bad "no register without credentials" || ok "no register without credentials"

echo "== opkg, aarch64, arch already listed"
new_root opkg-arm-generic opkg
STUB_ARCHES="all noarch aarch64_generic" run aarch64 --no-luci
called "opkg install " && [ ! -e "$ROOT/etc/opkg/leikwan-arch.conf" ] && ok "no arch change when aarch64_generic is listed" || bad "opkg call: $(cat "$CALLS")"

echo "== apk, aarch64, CPU-specific arch"
new_root apk-arm apk
echo aarch64_cortex-a53 > "$ROOT/etc/apk/arch"
run aarch64
called "apk add --allow-untrusted .*leikwand-1.2.6-r1.apk" && ! called "--arch" \
	&& ok "apk installs the arm64 apks" || bad "apk call: $(cat "$CALLS")"
[ "$(cat "$ROOT/etc/apk/arch")" = "aarch64_cortex-a53"$'\n'"aarch64_generic" ] \
	&& ok "/etc/apk/arch keeps the firmware arch and adds aarch64_generic" || bad "apk arch: $(cat "$ROOT/etc/apk/arch")"
run aarch64
[ "$(grep -c aarch64_generic "$ROOT/etc/apk/arch")" = 1 ] && ok "a second run does not add it twice" || bad "duplicate arch lines"

echo "== the manifest picks the current version, not an older file on disk"
new_root opkg-x86 opkg
STUB_ARCHES="all noarch x86_64" run x86_64
called "leikwand_1.2.6-r1_x86_64.ipk" && ! called "1.2.5" && ok "installs 1.2.6 only" || bad "opkg call: $(cat "$CALLS")"

echo "== checksum mismatch aborts before installing"
new_root apk-bad apk
echo x86_64 > "$ROOT/etc/apk/arch"
cp "$FILES_DIR/apk/amd64/leikwand-1.2.6-r1.apk" "$T/keep"
echo tampered > "$FILES_DIR/apk/amd64/leikwand-1.2.6-r1.apk"
run x86_64
cp "$T/keep" "$FILES_DIR/apk/amd64/leikwand-1.2.6-r1.apk"
[ "$RC" != 0 ] && ok "non-zero exit" || bad "tampered package accepted"
echo "$OUT" | grep -q "checksum mismatch" && ok "says checksum mismatch" || bad "message: $OUT"
called "apk add" && bad "installed despite the mismatch" || ok "nothing installed"

echo "== unsupported CPU"
new_root apk-mips apk
run mips
[ "$RC" != 0 ] && echo "$OUT" | grep -q "not supported" && ok "mips is refused with a reason" || bad "mips: rc=$RC $OUT"

echo "== dry run changes nothing"
new_root apk-dry apk
echo x86_64 > "$ROOT/etc/apk/arch"
run x86_64 --dry-run
[ "$RC" = 0 ] && echo "$OUT" | grep -q "would install apk/amd64/leikwand-1.2.6-r1.apk" && ! called "apk add" \
	&& ok "plan printed, nothing installed" || bad "dry run: $OUT / $(cat "$CALLS")"

echo "== refusals"
new_root none apk
rm "$ROOT/etc/openwrt_release"
run x86_64
[ "$RC" != 0 ] && echo "$OUT" | grep -q "does not look like OpenWrt" && ok "non-OpenWrt host refused" || bad "non-OpenWrt: $OUT"
new_root creds apk
run x86_64 --client-id 7
[ "$RC" != 0 ] && echo "$OUT" | grep -q "agent-token is required" && ok "client id without token refused" || bad "creds: $OUT"
run x86_64 --client-id abc --agent-token x
[ "$RC" != 0 ] && ok "non-numeric client id refused" || bad "client id abc accepted"

echo "== ambiguous manifest is refused"
new_root apk-dup apk
echo x86_64 > "$ROOT/etc/apk/arch"
cp "$PANEL/artifacts/openwrt-packages.txt" "$T/manifest.keep"
grep "apk/amd64/leikwand-" "$T/manifest.keep" | sed 's#1.2.6#1.2.7#' >> "$PANEL/artifacts/openwrt-packages.txt"
run x86_64
cp "$T/manifest.keep" "$PANEL/artifacts/openwrt-packages.txt"
[ "$RC" != 0 ] && echo "$OUT" | grep -q "no single apk entry for leikwand" && ok "two leikwand entries refused" || bad "ambiguous: $OUT"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
