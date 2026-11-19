#!/bin/bash
# Upgrade test for leikwand's uci-defaults (schema 4) on a real OpenWrt
# userland: the real uci binary, the real /lib/functions.sh and, optionally, a
# real opkg upgrade from an older leikwand package.
#
# The point is the legacy gateway routers: a 1.1.x install in gateway mode
# must keep every gateway key, its split carrier and its fw4 zone across the
# upgrade, while a router-edition install keeps the 1.2.x schema-3 cleanup.
#
# Usage: uci-defaults-upgrade.sh <openwrt-rootfs> [<old.ipk> <new.ipk>]
#
#   <openwrt-rootfs>  an extracted OpenWrt 24.10 rootfs, for example the image
#                     builder's build_dir/target-x86_64_musl/root.orig-x86
#   <old.ipk>         a leikwand 1.1.x package (gateway edition)
#   <new.ipk>         the leikwand package under test
#
# The rootfs is copied to a scratch directory and entered with
# "unshare -r -n chroot": no real root, no network, and the host's firewall is
# never touched. /etc/init.d/firewall is replaced by a stub that records
# reloads. Nothing in the copy is started (there is no procd or ubus).

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
UCI_DEFAULTS=${UCI_DEFAULTS:-$HERE/../leikwand/files/leikwand.uci-defaults}
ROOTFS=${1:-}
OLD_IPK=${2:-}
NEW_IPK=${3:-}

if [ -z "$ROOTFS" ] || [ ! -x "$ROOTFS/sbin/uci" ] || [ ! -f "$ROOTFS/lib/functions.sh" ]; then
	echo "usage: $0 <openwrt-rootfs> [<old.ipk> <new.ipk>]" >&2
	exit 2
fi

SCRATCH=$(mktemp -d)
trap 'rm -rf "$SCRATCH"' EXIT
R=$SCRATCH/root
cp -a "$ROOTFS" "$R"
mkdir -p "$R/tmp/lock" "$R/var/lock" "$R/tmp/.uci" 2>/dev/null || true
printf '#!/bin/sh\necho "$*" >> /tmp/firewall-calls\nexit 0\n' > "$R/etc/init.d/firewall"
chmod 755 "$R/etc/init.d/firewall"
cp "$R/etc/config/firewall" "$SCRATCH/firewall.stock"

passed=0
failed=0
ok() { echo "  PASS $1"; passed=$((passed + 1)); }
bad() { echo "  FAIL $1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | sed 's/^/       /'; failed=$((failed + 1)); }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got: $2"$'\n'"want: $3"; fi; }

in_root() {
	unshare -r -n chroot "$R" /bin/sh -c "$1"
}

ZONE='
config zone '"'"'leikwan'"'"'
	option name '"'"'leikwan'"'"'
	list device '"'"'lkwtun0'"'"'
	option input '"'"'ACCEPT'"'"'
	option output '"'"'ACCEPT'"'"'
	option forward '"'"'REJECT'"'"'
	option mtu_fix '"'"'1'"'"'

config forwarding '"'"'leikwan_lan'"'"'
	option src '"'"'lan'"'"'
	option dest '"'"'leikwan'"'"'
'

# The leikwand 1.1.1 package defaults (openwrt/leikwand/files/leikwan.config at
# 10b298e4), after its own uci-defaults (gateway_schema 2).
config_111() {
	cat <<'EOF'
config leikwan 'main'
	option enabled '1'
	option panel_url 'https://v3.leikwanhost.com'
	option fallback_panel_url 'https://api-leikwanbridge.nbwly.com'
	option panel_egress_interface 'auto'
	option device_name ''
	option device_id ''
	option mode 'forward'
	option poll_interval '30'
	option heartbeat_interval '30'
	option health_interval '20'
	option cold_start_grace '90'
	option restart_cooldown '30'
	option lease_renew_lead '90'
	option max_candidates '4'
	option auto_update '1'
	option log_level 'info'
	option data_dir '/etc/leikwan'
	option run_dir '/var/run/leikwand'
	option runtime_bin '/usr/libexec/leikwan/ultra-client'
	option gateway_enabled '0'
	option gateway_forward_id '0'
	option gateway_route_scope 'auto'
	option gateway_route_policy 'auto'
	option gateway_tun_address '172.19.0.1/30'
	option gateway_tun_mtu '1280'
	option gateway_dns_mode 'auto'
	option gateway_dns_servers ''
	option gateway_bypass_cn '1'
	option gateway_singbox_bin '/usr/bin/sing-box'
	option gateway_rule_set_dir '/usr/share/leikwan/rule-set'
	option gateway_split_forward_id '0'
	option gateway_split_route_policy 'auto'
	option gateway_split_cidr_file '/usr/share/leikwan/split/shixp-v4.txt'
	option gateway_capture 'full'
	option uplink_enabled '0'
	option uplink_scope 'relay'
	option uplink_interfaces ''
	option uplink_probe '1.1.1.1 223.5.5.5'
	option uplink_interval '5'
	option uplink_timeout_ms '2000'
	option uplink_fail_threshold '3'
	option uplink_recover_threshold '2'
	option uplink_min_improve_pct '20'
	option uplink_route_table '1001'
	option uplink_fwmark '19531'
	option cn_ip_list '/etc/leikwan/CN.conf'
	option gateway_schema '2'
EOF
}

# The 10.3.32.1 router on 1.1.1: gateway mode, primary #325, the SHIXP split
# carrier #326 through the legacy keys.
config_router_111() {
	config_111 | sed \
		-e "s/option mode 'forward'/option mode 'gateway'/" \
		-e "s/option gateway_enabled '0'/option gateway_enabled '1'/" \
		-e "s/option gateway_forward_id '0'/option gateway_forward_id '325'/" \
		-e "s/option gateway_split_forward_id '0'/option gateway_split_forward_id '326'/" \
		-e "s/option device_name ''/option device_name 'OpenWrt 10.3.32.1'/" \
		-e "s/option device_id ''/option device_id 'openwrt-e5b2578b'/"
}

# The 1.2.12 router edition after its schema-3 uci-defaults.
config_1212() {
	sed 's/\r$//' "$HERE/../leikwand/files/leikwan.config"
	printf "\toption schema '3'\n"
}

# The PVE host's 1.1.1 configuration (/etc/leikwan/leikwan.uci there; PVE has
# no opkg, but the same keys must survive if it ever is an OpenWrt box).
config_pve() {
	cat <<'EOF'
config leikwan 'main'
	option enabled '1'
	option mode 'gateway'
	option gateway_enabled '1'
	option gateway_forward_id '326'
	option gateway_capture 'cidr'
	option gateway_route_policy 'auto'
	option gateway_split_cidr_file '/etc/shixp/shixp-ixonly-v4.txt'
	option gateway_singbox_bin '/usr/local/bin/sing-box'
	option gateway_tun_address '172.19.0.1/30'
	option gateway_tun_mtu '1280'
	option run_dir '/run/leikwand'
	option runtime_bin '/usr/local/libexec/leikwan/ultra-client'
	option auto_update '0'
	option device_name 'PVE pootung (180.153.83.59)'
	option gateway_schema '2'
EOF
}

# Installs a case: the leikwan config, the stock firewall (plus the zone when
# asked), and the uci-defaults under test.
setup_case() {
	"$1" > "$R/etc/config/leikwan"
	cp "$SCRATCH/firewall.stock" "$R/etc/config/firewall"
	[ "$2" = "zone" ] && printf '%s' "$ZONE" >> "$R/etc/config/firewall"
	rm -f "$R/tmp/firewall-calls"
	in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
	in_root 'uci show firewall' > "$SCRATCH/firewall.before"
}

# Runs the uci-defaults exactly as default_postinst does on install/upgrade.
run_defaults() {
	sed 's/\r$//' "$UCI_DEFAULTS" > "$R/etc/uci-defaults/95-leikwand"
	chmod 755 "$R/etc/uci-defaults/95-leikwand"
	in_root '( [ -f /etc/uci-defaults/95-leikwand ] && cd /etc/uci-defaults && . /etc/uci-defaults/95-leikwand ) && rm -f /etc/uci-defaults/95-leikwand; uci commit'
	check "uci-defaults ran and was removed" "$([ -e "$R/etc/uci-defaults/95-leikwand" ] && echo left || echo removed)" removed
	in_root 'uci show leikwan' > "$SCRATCH/leikwan.after"
	in_root 'uci show firewall' > "$SCRATCH/firewall.after"
}

leikwan_diff() { diff "$SCRATCH/leikwan.before" "$SCRATCH/leikwan.after" | grep '^[<>]' | sort; }
firewall_diff() { diff "$SCRATCH/firewall.before" "$SCRATCH/firewall.after" | grep '^[<>]' | sort; }
reloads() { [ -f "$R/tmp/firewall-calls" ] && grep -c reload "$R/tmp/firewall-calls" || echo 0; }
get() { in_root "uci -q get $1" 2>/dev/null; }

echo "== 1.1.1 gateway router (mode gateway, #325 + split #326, zone) =="
setup_case config_router_111 zone
run_defaults
check "only the schema is added to leikwan" "$(leikwan_diff)" "> leikwan.main.schema='4'"
check "the firewall is untouched" "$(firewall_diff)" ""
check "no firewall reload" "$(reloads)" 0
check "mode stays gateway" "$(get leikwan.main.mode)" gateway
check "the split carrier stays" "$(get leikwan.main.gateway_split_forward_id)" 326
check "the split list stays" "$(get leikwan.main.gateway_split_cidr_file)" /usr/share/leikwan/split/shixp-v4.txt
check "the zone keeps lkwtun0" "$(get firewall.leikwan.device)" lkwtun0
check "lan forwards into the zone" "$(get firewall.leikwan_lan.dest)" leikwan

echo "== the same router run twice (idempotent) =="
in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
in_root 'uci show firewall' > "$SCRATCH/firewall.before"
run_defaults
check "a second run changes nothing in leikwan" "$(leikwan_diff)" ""
check "a second run changes nothing in the firewall" "$(firewall_diff)" ""
check "still no firewall reload" "$(reloads)" 0

echo "== 1.1.1 gateway config without the zone =="
setup_case config_router_111 none
run_defaults
check "only the schema is added to leikwan" "$(leikwan_diff)" "> leikwan.main.schema='4'"
check "the zone is created" "$(get firewall.leikwan)" zone
check "the zone holds lkwtun0" "$(get firewall.leikwan.device)" lkwtun0
check "the zone rejects forwarding out of the tunnel" "$(get firewall.leikwan.forward)" REJECT
check "lan forwards into the zone" "$(get firewall.leikwan_lan.src)/$(get firewall.leikwan_lan.dest)" lan/leikwan
check "one firewall reload" "$(reloads)" 1

echo "== PVE-style cidr gateway config =="
setup_case config_pve none
run_defaults
# panel_egress_interface is filled in when absent, exactly as 1.1.1 does.
check "only the schema (and the 1.1.1 egress default) is added" "$(leikwan_diff)" "> leikwan.main.panel_egress_interface='auto'
> leikwan.main.schema='4'"
check "cidr capture stays" "$(get leikwan.main.gateway_capture)" cidr

echo "== split sections (mode gateway) =="
setup_case config_router_111 zone
in_root "uci set leikwan.pudong=split; uci set leikwan.pudong.forward_id=330; uci set leikwan.pudong.cidr_file=/etc/leikwan/pudong.txt; uci set leikwan.pudong.route_policy=auto; uci set leikwan.pudong.enabled=1; uci commit leikwan"
in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
run_defaults
check "only the schema is added to leikwan" "$(leikwan_diff)" "> leikwan.main.schema='4'"
check "the split section stays" "$(get leikwan.pudong.forward_id)" 330

echo "== a split section alone marks a gateway install =="
setup_case config_1212 none
in_root "uci set leikwan.pudong=split; uci set leikwan.pudong.forward_id=330; uci commit leikwan"
in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
run_defaults
check "nothing is deleted; the schemas move" "$(leikwan_diff)" "> leikwan.main.gateway_schema='2'
< leikwan.main.schema='3'
> leikwan.main.schema='4'"
check "no zone without mode=gateway" "$(get firewall.leikwan || echo absent)" absent

echo "== 1.1.1 forward-only install (gateway keys present, gateway off, zone) =="
setup_case config_111 zone
run_defaults
check "only the schema is added to leikwan" "$(leikwan_diff)" "> leikwan.main.schema='4'"
check "the (inert) zone is kept" "$(firewall_diff)" ""
check "no firewall reload" "$(reloads)" 0

echo "== 1.2.12 router edition (schema 3) =="
setup_case config_1212 none
run_defaults
check "only the schema moves" "$(leikwan_diff)" "< leikwan.main.schema='3'
> leikwan.main.schema='4'"
check "no zone is created" "$(get firewall.leikwan || echo absent)" absent
check "no firewall reload" "$(reloads)" 0

echo "== 1.2.12 router edition with a relay carrier (no gateway markers) =="
setup_case config_1212 none
in_root "uci set leikwan.main.gateway_forward_id=7; uci set leikwan.main.gateway_route_scope=intl_only; uci set leikwan.main.gateway_route_policy=unrestricted; uci commit leikwan"
in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
run_defaults
check "the relay carrier, scope and policy are kept" "$(leikwan_diff)" "< leikwan.main.schema='3'
> leikwan.main.schema='4'"

echo "== 1.2.12 router edition with a stale zone =="
setup_case config_1212 zone
run_defaults
check "the stale zone is removed (as in 1.2.x)" "$(get firewall.leikwan || echo absent)/$(get firewall.leikwan_lan || echo absent)" absent/absent
check "one firewall reload" "$(reloads)" 1

echo "== pre-1.2 forward-only install (uplink keys, no gateway-only keys, zone) =="
setup_case config_1212 zone
in_root "uci delete leikwan.main.schema; uci set leikwan.main.mode=forward; uci set leikwan.main.uplink_enabled=0; uci set leikwan.main.uplink_interfaces='wan wanb'; uci set leikwan.main.uplink_fwmark=19531; uci set leikwan.main.gateway_forward_id=12; uci commit leikwan"
in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
run_defaults
check "the schema-3 cleanup runs" "$(get leikwan.main.mode || echo none)/$(get leikwan.main.uplink_interfaces || echo none)/$(get leikwan.main.gateway_forward_id)/$(get leikwan.main.schema)" none/none/0/4
check "the zone is removed" "$(get firewall.leikwan || echo absent)" absent

echo "== pre-1.1 gateway (gateway schema 1) gets the 1.1 migration =="
setup_case config_router_111 zone
in_root "uci delete leikwan.main.gateway_schema; uci set leikwan.main.gateway_tun_mtu=1420; uci set leikwan.main.gateway_route_scope=global; uci set leikwan.main.gateway_dns_servers=1.1.1.1; uci set leikwan.main.gateway_redirect_lan=1; uci commit leikwan"
run_defaults
check "the 1.1 defaults are applied once" "$(get leikwan.main.gateway_tun_mtu)/$(get leikwan.main.gateway_route_scope)/$(get leikwan.main.gateway_dns_servers)/$(get leikwan.main.gateway_redirect_lan || echo gone)/$(get leikwan.main.gateway_schema)" "1280/auto//gone/2"
check "mode and carriers stay" "$(get leikwan.main.mode)/$(get leikwan.main.gateway_forward_id)/$(get leikwan.main.gateway_split_forward_id)" gateway/325/326

if [ -n "$OLD_IPK" ] && [ -n "$NEW_IPK" ]; then
	echo "== opkg upgrade: $(basename "$OLD_IPK") -> $(basename "$NEW_IPK") =="
	cp "$OLD_IPK" "$R/tmp/old.ipk"
	cp "$NEW_IPK" "$R/tmp/new.ipk"
	cp "$SCRATCH/firewall.stock" "$R/etc/config/firewall"
	rm -f "$R/tmp/firewall-calls"
	# Start from a clean slate whatever the rootfs shipped (luci-app-leikwan
	# depends on leikwand, so it goes first).
	in_root 'opkg remove luci-app-leikwan >/dev/null 2>&1; opkg remove leikwand >/dev/null 2>&1; rm -f /etc/config/leikwan /etc/config/leikwan-opkg /etc/uci-defaults/95-leikwand; true'
	check "the rootfs has no leikwand left" "$(in_root 'opkg status leikwand' | sed -n 's/^Version: //p')" ""
	in_root 'opkg install --nodeps /tmp/old.ipk' > "$SCRATCH/opkg-old.log" 2>&1
	check "the old package installs" "$(in_root 'opkg status leikwand' | sed -n 's/^Version: //p')" "$(tar -xzOf "$OLD_IPK" ./control.tar.gz 2>/dev/null | tar -xzO ./control 2>/dev/null | sed -n 's/^Version: //p')"
	# The operator's gateway configuration on top of the old defaults.
	in_root "uci set leikwan.main.mode=gateway; uci set leikwan.main.gateway_enabled=1; uci set leikwan.main.gateway_forward_id=325; uci set leikwan.main.gateway_split_forward_id=326; uci set leikwan.main.device_name='OpenWrt 10.3.32.1'; uci commit leikwan"
	in_root 'uci show leikwan' > "$SCRATCH/leikwan.before"
	in_root 'uci show firewall' > "$SCRATCH/firewall.before"
	check "the old package created the zone" "$(get firewall.leikwan)" zone
	in_root 'opkg install --nodeps /tmp/new.ipk' > "$SCRATCH/opkg-new.log" 2>&1
	check "the new package is installed" "$(in_root 'opkg status leikwand' | sed -n 's/^Version: //p')" "$(tar -xzOf "$NEW_IPK" ./control.tar.gz 2>/dev/null | tar -xzO ./control 2>/dev/null | sed -n 's/^Version: //p')"
	in_root 'uci show leikwan' > "$SCRATCH/leikwan.after"
	in_root 'uci show firewall' > "$SCRATCH/firewall.after"
	check "the upgrade only adds the schema to leikwan" "$(leikwan_diff)" "> leikwan.main.schema='4'"
	check "the upgrade leaves the firewall untouched" "$(firewall_diff)" ""
	check "the new uci-defaults ran" "$([ -e "$R/etc/uci-defaults/95-leikwand" ] && echo pending || echo ran)" ran
	check "opkg kept the operator's conffile" "$([ -f "$R/etc/config/leikwan-opkg" ] && echo kept || echo replaced)" kept
	for file in /usr/share/leikwan/rule-set/geoip-cn.srs /usr/share/leikwan/rule-set/geosite-cn.srs /usr/share/leikwan/split/shixp-v4.txt; do
		check "$file is installed" "$([ -s "$R$file" ] && echo yes || echo no)" yes
	done
	check "the split list has the 1.1.1 prefixes" "$(grep -cE '^[0-9]' "$R/usr/share/leikwan/split/shixp-v4.txt")" 357
	in_root 'opkg remove leikwand' > "$SCRATCH/opkg-remove.log" 2>&1
	check "a real removal drops the zone" "$(get firewall.leikwan || echo absent)/$(get firewall.leikwan_lan || echo absent)" absent/absent
	if [ "$failed" != 0 ]; then
		echo "--- opkg logs"; cat "$SCRATCH/opkg-old.log" "$SCRATCH/opkg-new.log" "$SCRATCH/opkg-remove.log"
	fi
fi

echo "== result =="
echo "$passed passed, $failed failed"
[ "$failed" = 0 ]
