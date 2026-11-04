#!/usr/bin/env bash
# Unit test for install/openwrt-versions.sh.
#
# Same shape as test_install_openwrt.sh: a scratch router root, stub apk / opkg /
# leikwand commands and a local manifest, so every detection and comparison
# branch runs without a router. The router VM suite runs the script on real
# stock images of both package-manager generations.
set -uo pipefail
cd "$(dirname "$0")/../.."
SCRIPT="$PWD/install/openwrt-versions.sh"
T=$(mktemp -d /tmp/leikwan-versions-test-XXXXXX)
trap 'rm -rf "$T"' EXIT
PASS=0
FAIL=0
ok()  { PASS=$((PASS+1)); echo "  PASS $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }

# --- a fake panel ----------------------------------------------------------
PANEL="$T/panel"
MANIFEST="$PANEL/artifacts/openwrt-packages.txt"
mkdir -p "$PANEL/artifacts"
write_manifest() { # write_manifest <leikwand-version> [runtime-version] [luci-version]
	{
		echo "# test manifest"
		echo "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  amd64/leikwand_$1_x86_64.ipk"
		echo "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  amd64/leikwan-runtime_${2:-$1}_x86_64.ipk"
		echo "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc  amd64/luci-app-leikwan_${3:-1.1.1-r1}_all.ipk"
		echo "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd  arm64/leikwand_$1_aarch64_generic.ipk"
		echo "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee  arm64/leikwan-runtime_${2:-$1}_aarch64_generic.ipk"
		echo "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff  arm64/luci-app-leikwan_${3:-1.1.1-r1}_all.ipk"
		echo "1111111111111111111111111111111111111111111111111111111111111111  apk/amd64/leikwand-$1.apk"
		echo "2222222222222222222222222222222222222222222222222222222222222222  apk/amd64/leikwan-runtime-${2:-$1}.apk"
		echo "3333333333333333333333333333333333333333333333333333333333333333  apk/amd64/luci-app-leikwan-${3:-1.1.1-r1}.apk"
		echo "4444444444444444444444444444444444444444444444444444444444444444  apk/arm64/leikwand-$1.apk"
		echo "5555555555555555555555555555555555555555555555555555555555555555  apk/arm64/leikwan-runtime-${2:-$1}.apk"
		echo "6666666666666666666666666666666666666666666666666666666666666666  apk/arm64/luci-app-leikwan-${3:-1.1.1-r1}.apk"
	} > "$MANIFEST"
}
write_manifest 1.2.7-r1

# --- stub commands ---------------------------------------------------------
# new_root <name> <pm> <installed-leikwand-version-or-empty>
# STUB_INSTALLED_<pkg> overrides the version reported for a package; an empty
# value means "not installed".
new_root() {
	ROOT="$T/$1"; BIN="$ROOT/stubbin"; CALLS="$ROOT/calls"
	mkdir -p "$ROOT/etc/init.d" "$ROOT/usr/bin" "$ROOT/usr/libexec/leikwan" "$BIN"
	: > "$CALLS"
	echo "DISTRIB_ID='OpenWrt'"$'\n'"DISTRIB_RELEASE='test'"$'\n'"DISTRIB_ARCH='test_arch'" > "$ROOT/etc/openwrt_release"
	cat > "$ROOT/etc/init.d/leikwand" <<EOF
#!/bin/sh
echo "init \$*" >> "$CALLS"
case "\$1" in
	enabled|running) exit \${STUB_SERVICE_RC:-0} ;;
esac
EOF
	cat > "$ROOT/usr/bin/leikwand" <<'EOF'
#!/bin/sh
case "$1" in version) echo "${STUB_BIN_VERSION:-1.2.7}" ;; esac
EOF
	cp "$ROOT/usr/bin/leikwand" "$ROOT/usr/libexec/leikwan/ultra-client"
	# The version script probes the runtime with `version` too; the real
	# runtime answers the same way.
	if [ "$2" = apk ]; then
		mkdir -p "$ROOT/etc/apk"; : > "$ROOT/etc/apk/world"
		# The installed version per package lives in STUB_INSTALLED_<name>;
		# the name is built in two steps because ${X_$(cmd)} is not POSIX.
		cat > "$BIN/apk" <<'EOF'
#!/bin/sh
echo "apk $*" >> "$CALLS"
pkg=""
case "$*" in
	"info -e -v "*) pkg=${*##* } ;;
	"info -e "*) pkg=${*##* } ;;
esac
[ -n "$pkg" ] || exit 0
key=$(echo "$pkg" | tr '-' '_')
v=$(eval "echo \"\${STUB_INSTALLED_$key:-}\"")
case "$*" in
	"info -e -v "*) [ -n "$v" ] && echo "$pkg-$v" ;;
	"info -e luci-base") [ -n "$v" ] ;;
	"info -e "*) [ -n "$v" ] ;;
esac
exit 0
EOF
	else
		mkdir -p "$ROOT/usr/lib/opkg"
		cat > "$BIN/opkg" <<'EOF'
#!/bin/sh
echo "opkg $*" >> "$CALLS"
case "$1" in
	status)
		v=$(eval "echo \"\${STUB_INSTALLED_$(echo "$2" | tr '-' '_'):-}\"")
		[ -n "$v" ] || v=$(eval "echo \"\${STUB_LUCI_BASE:-}\"")
		[ -n "$v" ] && printf 'Package: %s\nVersion: %s\nStatus: install user installed\n' "$2" "${v:-1.0.0}"
		;;
esac
exit 0
EOF
	fi
	chmod +x "$BIN"/* "$ROOT/etc/init.d/leikwand" "$ROOT/usr/bin/leikwand" "$ROOT/usr/libexec/leikwan/ultra-client"
}

run() { # run <machine> [args...]  -> $OUT, $RC
	local machine="$1"; shift
	OUT=$(env -i PATH="$BIN:/usr/bin:/bin" HOME="$T" LEIKWAN_ROOT="$ROOT" LEIKWAN_MACHINE="$machine" \
		STUB_LUCI_BASE="${STUB_LUCI_BASE:-}" \
		STUB_INSTALLED_luci_base="${STUB_INSTALLED_luci_base:-}" \
		STUB_INSTALLED_leikwand="${STUB_INSTALLED_leikwand:-}" \
		STUB_INSTALLED_leikwan_runtime="${STUB_INSTALLED_leikwan_runtime:-}" \
		STUB_INSTALLED_luci_app_leikwan="${STUB_INSTALLED_luci_app_leikwan:-}" \
		STUB_BIN_VERSION="${STUB_BIN_VERSION:-1.2.7}" \
		sh "$SCRIPT" --panel "file://$PANEL" "$@" 2>&1)
	RC=$?
}

echo "== opkg, up to date"
new_root opkg-ok opkg
STUB_LUCI_BASE=1 STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64
[ "$RC" = 0 ] && ok "exit 0 when nothing is pending" || bad "exit $RC: $OUT"
echo "$OUT" | grep -q "opkg (.ipk" && ok "detects opkg" || bad "package manager: $OUT"
echo "$OUT" | grep -q "x86_64 -> amd64 build" && ok "detects the amd64 build" || bad "arch: $OUT"
echo "$OUT" | grep -q "up to date" && ok "reports up to date" || bad "state: $OUT"
echo "$OUT" | grep -q "3 for this router (amd64/)" && ok "counts the router's manifest entries" || bad "count: $OUT"
echo "$OUT" | grep -q "Nothing to change" && ok "closes with nothing to change" || bad "closing: $OUT"

echo "== opkg, update pending"
new_root opkg-old opkg
STUB_LUCI_BASE=1 STUB_INSTALLED_leikwand=1.2.5-r1 STUB_INSTALLED_leikwan_runtime=1.2.5-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64
[ "$RC" = 0 ] && ok "the report still exits 0" || bad "exit $RC: $OUT"
echo "$OUT" | grep -q "UPDATE -> amd64/leikwand_1.2.7-r1_x86_64.ipk" && ok "reports the pending leikwand update" || bad "update: $OUT"
echo "$OUT" | grep -q "sh install-openwrt.sh" && ok "suggests the installer" || bad "hint missing: $OUT"
STUB_LUCI_BASE=1 STUB_INSTALLED_leikwand=1.2.5-r1 STUB_INSTALLED_leikwan_runtime=1.2.5-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64 --check
[ "$RC" = 1 ] && ok "--check exits 1 when an update is published" || bad "--check exit $RC: $OUT"
echo "$OUT" | grep -q "leikwand 1.2.5-r1 -> 1.2.7-r1" && ok "--check names the versions" || bad "--check output: $OUT"
STUB_LUCI_BASE=1 STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64 --check
[ "$RC" = 0 ] && ok "--check exits 0 when current" || bad "--check exit $RC"

echo "== nothing installed yet"
new_root opkg-empty opkg
STUB_LUCI_BASE=1 run x86_64
echo "$OUT" | grep -q "UPDATE -> amd64/leikwand_1.2.7-r1_x86_64.ipk" && ok "an unreported package counts as an update" || bad "empty: $OUT"

echo "== the ipk version survives an arch name with underscores"
new_root opkg-arch-underscore opkg
STUB_LUCI_BASE=1 STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64 --json
echo "$OUT" | grep -q '"published":"1.2.7-r1","file":"amd64/leikwand_1.2.7-r1_x86_64.ipk"' \
	&& ok "x86_64 is not mistaken for part of the version" || bad "json: $OUT"

echo "== apk, up to date (the apk stub resolves versions too)"
new_root apk-ok apk
echo x86_64 > "$ROOT/etc/apk/arch"
STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64 --check
[ "$RC" = 0 ] && ok "apk: --check exits 0 when current" || bad "apk check: rc=$RC $OUT"

echo "== apk, aarch64, CPU-specific firmware"
new_root apk-arm apk
echo aarch64_cortex-a53 > "$ROOT/etc/apk/arch"
STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 run aarch64
echo "$OUT" | grep -q "arm64 build (package arch aarch64_generic)" && ok "resolves the package arch" || bad "arch: $OUT"
echo "$OUT" | grep -q "this router installs: apk/arm64/leikwand-1.2.7-r1.apk" && ok "names the apk it would install" || bad "target: $OUT"
echo "$OUT" | grep -q "not applicable (no ucode LuCI)" && ok "LuCI not applicable without ucode" || bad "luci: $OUT"
[ "$(cat "$ROOT/etc/apk/arch")" = "aarch64_cortex-a53" ] && ok "does not touch /etc/apk/arch (report only)" || bad "arch file changed"

echo "== --detect"
new_root apk-x86 apk
echo x86_64 > "$ROOT/etc/apk/arch"
ln -s /bin/true "$BIN/ucode"
STUB_LUCI_BASE=1 STUB_INSTALLED_luci_base=1 STUB_INSTALLED_leikwand=1.2.7-r1 run x86_64 --detect
echo "$OUT" | grep -qx "package_manager=apk" && echo "$OUT" | grep -qx "format=apk" \
	&& echo "$OUT" | grep -qx "arch=amd64" && echo "$OUT" | grep -qx "luci=yes" \
	&& ok "--detect prints key=value detection" || bad "detect: $OUT"

echo "== --target"
new_root opkg-tgt opkg
run x86_64 --target
[ "$RC" = 0 ] && [ "$OUT" = "amd64/leikwand_1.2.7-r1_x86_64.ipk" ] && ok "--target prints the manifest path" || bad "target: rc=$RC $OUT"

echo "== --json is valid JSON"
new_root json apk
echo x86_64 > "$ROOT/etc/apk/arch"
STUB_LUCI_BASE=1 STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 \
	STUB_INSTALLED_luci_app_leikwan=1.1.1-r1 run x86_64 --json
if command -v python3 >/dev/null 2>&1; then
	echo "$OUT" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["update_available"] is False; assert d["packages"]["leikwand"]["installed"]=="1.2.7-r1"' 2>/dev/null \
		&& ok "the JSON parses and holds the expected values" || bad "json parse: $OUT"
else
	echo "$OUT" | grep -q '"update_available":false' && ok "the JSON holds the expected values" || bad "json: $OUT"
fi

echo "== a firmware whose text contains a quote stays valid JSON"
new_root json-quote opkg
printf "DISTRIB_ID='OpenWrt'\nDISTRIB_RELEASE='\"quoted\"'\nDISTRIB_ARCH='x86_64'\n" > "$ROOT/etc/openwrt_release"
run x86_64 --json
if command -v python3 >/dev/null 2>&1; then
	echo "$OUT" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null \
		&& ok "the release string is escaped" || bad "unescaped quote: $OUT"
fi

echo "== --offline never contacts the panel"
new_root offline opkg
STUB_INSTALLED_leikwand=1.2.7-r1 run x86_64 --offline
[ "$RC" = 0 ] && echo "$OUT" | grep -q "not queried (--offline)" && ok "says the panel was not queried" || bad "offline: $OUT"
echo "$OUT" | grep -q "Only the local side is known" && ok "does not claim up to date" || bad "offline closing: $OUT"

echo "== an unreachable panel is reported, not guessed"
new_root unreachable opkg
STUB_INSTALLED_leikwand=1.2.7-r1 run x86_64 --panel "file://$T/does-not-exist" --check
[ "$RC" = 2 ] && echo "$OUT" | grep -q "cannot compare" && ok "--check exits 2 without a manifest" || bad "unreachable: rc=$RC $OUT"

echo "== an ambiguous manifest is reported, not silently resolved"
new_root dup apk
echo x86_64 > "$ROOT/etc/apk/arch"
cp "$MANIFEST" "$T/manifest.keep"
echo "9999999999999999999999999999999999999999999999999999999999999999  apk/amd64/leikwand-1.2.7-r1.apk" >> "$MANIFEST"
run x86_64 --target
cp "$T/manifest.keep" "$MANIFEST"
[ "$RC" = 2 ] && echo "$OUT" | grep -q "no single leikwand entry" && ok "two leikwand entries are refused" || bad "dup: rc=$RC $OUT"

echo "== a package new to the manifest is not a downgrade"
new_root newluci apk
echo x86_64 > "$ROOT/etc/apk/arch"
STUB_INSTALLED_leikwand=1.2.7-r1 STUB_INSTALLED_leikwan_runtime=1.2.7-r1 run x86_64 --check
[ "$RC" = 1 ] && ok "an installed-core/absent-luci router is told an update is available" || bad "newpackage: rc=$RC $OUT"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
