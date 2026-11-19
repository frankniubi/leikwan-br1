#!/bin/bash
# Validate the gateway-mode sing-box configuration with the real sing-box
# binary that OpenWrt 24.10 ships.
#
# Why this exists: inspecting a Go struct proves nothing about whether sing-box
# accepts it. This script caught a fatal defect — the config used the per-rule
# "geoip" field that sing-box removed in 1.12 — which would have made gateway
# mode fail to start on every router while every unit test still passed.
#
# Usage: ./validate_gateway_config.sh <path-to-linux-leikwand> [work-dir]
#
# Requires: curl, tar, python3, network access to downloads.openwrt.org.
set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)

LEIKWAND="${1:?path to a linux/amd64 leikwand binary}"
WORK="${2:-/tmp/leikwan-gateway-check}"
OPENWRT_VERSION="${OPENWRT_VERSION:-24.10.4}"
PKGS="https://downloads.openwrt.org/releases/${OPENWRT_VERSION}/packages/x86_64/packages"

rm -rf "$WORK"
mkdir -p "$WORK/root"
cd "$WORK"

extract_ipk() { # absolute ipk path -> payload into $WORK/root
	local src; src=$(readlink -f "$1")
	rm -rf pkgx && mkdir pkgx
	( cd pkgx && tar -xzf "$src" && tar -xzf data.tar.gz -C "$WORK/root" 2>/dev/null || true )
}

echo "== downloading the OpenWrt sing-box package =="
curl -fsSL "$PKGS/Packages.gz" | gunzip -c > packages.txt
SINGBOX_IPK=$(awk '/^Package: sing-box$/{s=1} s&&/^Filename:/{print $2; exit}' packages.txt)
[ -n "$SINGBOX_IPK" ] || { echo "sing-box is not published for this release" >&2; exit 2; }
curl -fsSL "$PKGS/$SINGBOX_IPK" -o "$WORK/sing-box.ipk"
extract_ipk "$WORK/sing-box.ipk"

# sing-box links against musl and libgcc, which a glibc host does not provide.
# The target rootfs carries both, and the musl loader can be invoked directly,
# so no container, chroot or root privilege is needed.
#
# libc is not published in the target package repository (it is part of the
# rootfs), so the rootfs tarball is the only self-contained source for the
# loader. An SDK that has already built the packages is used first because it
# is faster and offline.
echo "== staging the target musl runtime =="
stage_from_sdk() {
	local sdk="${LEIKWAN_SDK:-$HOME/leikwan-build/sdk}"
	local found=0
	for ipk in "$sdk"/bin/targets/*/packages/libc_*.ipk "$sdk"/bin/targets/*/packages/libgcc1_*.ipk; do
		[ -f "$ipk" ] || continue
		extract_ipk "$ipk"
		found=1
	done
	[ "$found" = "1" ]
}

if ! stage_from_sdk || [ -z "$(find "$WORK/root" -name 'ld-musl-x86_64.so.1' 2>/dev/null)" ]; then
	ROOTFS_URL="https://downloads.openwrt.org/releases/${OPENWRT_VERSION}/targets/x86/64/openwrt-${OPENWRT_VERSION}-x86-64-rootfs.tar.gz"
	echo "    fetching the target rootfs for the musl loader"
	curl -fsSL "$ROOTFS_URL" -o "$WORK/rootfs.tar.gz" || {
		echo "could not obtain a musl loader; set LEIKWAN_SDK to a built SDK" >&2
		exit 2
	}
	# The whole rootfs is only a few megabytes, and unpacking it wholesale is
	# far more robust than trying to match member names across tar variants.
	tar -xzf "$WORK/rootfs.tar.gz" -C "$WORK/root"
	if [ ! -f "$WORK/root/lib/ld-musl-x86_64.so.1" ]; then
		echo "the rootfs tarball did not contain a musl loader" >&2
		exit 2
	fi
fi

cp "$WORK/root/usr/bin/sing-box" "$WORK/sing-box"
chmod +x "$WORK/sing-box" "$LEIKWAND"
LOADER=$(find "$WORK/root" -name 'ld-musl-x86_64.so.1' | head -1)
LIBS=$(dirname "$(find "$WORK/root" -name 'libgcc_s.so.1' | head -1)")
[ -n "$LOADER" ] || { echo "musl loader not found; set LEIKWAN_SDK to a built SDK" >&2; exit 2; }

run_singbox() { "$LOADER" --library-path "$LIBS" "$WORK/sing-box" "$@"; }
echo "sing-box: $(run_singbox version 2>&1 | head -1)"

# The bundled rule-sets the leikwand package installs under
# /usr/share/leikwan/rule-set. "remote" exercises the fallback definitions.
RULESETS="$(cd "$SCRIPT_DIR/../../../openwrt/leikwand/files/rule-set" && pwd)"

pass=0
fail=0
for scope in global cn_only intl_only; do
	for policy in restricted unrestricted; do
		for bypass in 1 0; do
			for dns in auto custom; do
				for sets in bundled remote; do
					label="$scope/$policy/bypass_cn=$bypass/dns=$dns/rule_sets=$sets"
					key="$scope-$policy-$bypass-$dns-$sets"
					uci="$WORK/uci-$key"
					set_dir="$RULESETS"
					[ "$sets" = "remote" ] && set_dir="$WORK/no-rule-sets"
					cat > "$uci" <<UCI
config leikwan 'main'
	option enabled '1'
	option mode 'gateway'
	option gateway_enabled '1'
	option gateway_route_scope '$scope'
	option gateway_route_policy '$policy'
	option gateway_bypass_cn '$bypass'
	option gateway_dns_mode '$dns'
	option gateway_dns_servers '8.8.8.8 9.9.9.9:5353'
	option gateway_rule_set_dir '$set_dir'
UCI
					cfg="$WORK/cfg-$key.json"
					if ! LEIKWAN_UCI_PATH="$uci" "$LEIKWAND" gateway-config > "$cfg" 2>"$WORK/err"; then
						echo "  FAIL $label: the agent produced no configuration ($(head -c 120 "$WORK/err"))"
						fail=$((fail + 1)); continue
					fi
					# "check" only parses; remote rule-sets are not fetched, so
					# both variants validate offline.
					if out=$(cd "$WORK" && run_singbox check -c "$cfg" 2>&1); then
						if printf '%s' "$out" | grep -qi 'deprecat\|warn'; then
							echo "  FAIL $label: accepted with a warning — $(printf '%s' "$out" | head -1)"
							fail=$((fail + 1))
						else
							pass=$((pass + 1))
						fi
					else
						echo "  FAIL $label: $(printf '%s' "$out" | head -2 | tr '\n' ' ')"
						fail=$((fail + 1))
					fi
				done
			done
		done
	done
done

# The split carrier (packaged SHIXP list on a second SOCKS outbound) on top
# of every primary scope and policy, with both split policies.
SPLIT_LIST="$(cd "$SCRIPT_DIR/../../../openwrt/leikwand/files/split" && pwd)/shixp-v4.txt"
for scope in global cn_only intl_only; do
	for policy in restricted unrestricted; do
		for split_policy in restricted unrestricted; do
			label="split/$scope/$policy/split=$split_policy"
			key="split-$scope-$policy-$split_policy"
			uci="$WORK/uci-$key"
			cat > "$uci" <<UCI
config leikwan 'main'
	option enabled '1'
	option mode 'gateway'
	option gateway_enabled '1'
	option gateway_route_scope '$scope'
	option gateway_route_policy '$policy'
	option gateway_rule_set_dir '$RULESETS'
	option gateway_split_forward_id '326'
	option gateway_split_route_policy '$split_policy'
	option gateway_split_cidr_file '$SPLIT_LIST'
UCI
			cfg="$WORK/cfg-$key.json"
			if ! LEIKWAN_UCI_PATH="$uci" "$LEIKWAND" gateway-config > "$cfg" 2>"$WORK/err" || ! grep -q '"tag": "split"' "$cfg"; then
				echo "  FAIL $label: no split outbound rendered ($(head -c 120 "$WORK/err"))"
				fail=$((fail + 1)); continue
			fi
			if out=$(cd "$WORK" && run_singbox check -c "$cfg" 2>&1) && ! printf '%s' "$out" | grep -qi 'deprecat\|warn'; then
				pass=$((pass + 1))
			else
				echo "  FAIL $label: $(printf '%s' "$out" | head -2 | tr '\n' ' ')"
				fail=$((fail + 1))
			fi
		done
	done
done

echo
echo "== scope semantics (catch-all outbound, mainland rule, DNS final) =="
for scope in global cn_only intl_only; do
	for policy in restricted unrestricted; do
		python3 - "$WORK/cfg-$scope-$policy-1-auto-bundled.json" "$scope/$policy" <<'PY'
import json, sys
config = json.load(open(sys.argv[1]))
route, dns = config['route'], config['dns']
cn = [r for r in route['rules'] if 'geosite-cn' in (r.get('rule_set') or [])]
servers = {s['tag']: s for s in dns['servers']}
remote = servers[dns['final']]
print('  %-24s final=%-6s mainland->%-6s dns=%s via %s' % (sys.argv[2], route['final'],
      cn[0]['outbound'] if cn else 'none', remote.get('server'), remote.get('detour', 'direct')))
PY
	done
done

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
