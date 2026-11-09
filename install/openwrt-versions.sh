#!/bin/sh
# Collect every Leikwan OpenWrt version that matters on this router, side by
# side, and say which build the router should be running.
#
# One script for both package-manager generations and all four package
# flavours: it answers "what do I have, what does the panel have, and which
# file applies to me" without installing anything or changing state.
#
#   sh openwrt-versions.sh              the report
#   sh openwrt-versions.sh --check      exit 1 when an update is available
#   sh openwrt-versions.sh --json       the same data as one JSON object
#   sh openwrt-versions.sh --detect     only the detection: arch, format, ...
#   sh openwrt-versions.sh --target     the manifest path this router installs
#   sh openwrt-versions.sh --all        also list every package in the manifest,
#                                       including the other architectures
#
# Exit codes: 0 up to date (or nothing to compare), 1 update available,
# 2 the report could not be built.
#
# Runs under BusyBox ash: no bash features.

set -u

PANEL="${LEIKWAN_PANEL:-https://v3.leikwanhost.com}"
ROOT="${LEIKWAN_ROOT:-}"
MODE=report
OFFLINE=0

log() { echo "leikwan-versions: $*"; }
die() { echo "leikwan-versions: ERROR: $*" >&2; exit 2; }

usage() {
	cat <<'EOF'
Usage: openwrt-versions.sh [options]

  --panel <url>    panel address (default https://v3.leikwanhost.com)
  --check          exit 1 when a newer package is published
  --json           print one JSON object instead of the report
  --detect         print the detection only (firmware, format, arch, ...)
  --target         print the manifest path this router would install
  --all            list every manifest entry, not just this router's
  --offline        do not contact the panel; report the local side only
  --help
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--panel) [ "$#" -ge 2 ] || die "--panel needs a value"; PANEL="$2"; shift 2 ;;
		--check) MODE=check; shift ;;
		--json) MODE=json; shift ;;
		--detect) MODE=detect; shift ;;
		--target) MODE=target; shift ;;
		--all) MODE=all; shift ;;
		--offline) OFFLINE=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; die "unknown option: $1" ;;
	esac
done
PANEL="${PANEL%/}"

# --- detection -------------------------------------------------------------
# The same rules as install-openwrt.sh: a firmware is not what its binaries
# happen to be, it is what its package database says.

RELEASE_FILE="$ROOT/etc/openwrt_release"
if [ -f "$RELEASE_FILE" ]; then
	DISTRIB_ID="" DISTRIB_RELEASE="" DISTRIB_ARCH=""
	# shellcheck disable=SC1090
	. "$RELEASE_FILE"
	FIRMWARE="${DISTRIB_ID:-OpenWrt} ${DISTRIB_RELEASE:-unknown}"
else
	DISTRIB_ARCH=""
	FIRMWARE="not OpenWrt"
fi

if [ -f "$ROOT/etc/apk/world" ] && command -v apk >/dev/null 2>&1; then
	PM=apk
elif [ -d "$ROOT/usr/lib/opkg" ] && command -v opkg >/dev/null 2>&1; then
	PM=opkg
elif command -v apk >/dev/null 2>&1; then
	PM=apk
elif command -v opkg >/dev/null 2>&1; then
	PM=opkg
else
	PM=none
fi

MACHINE="${LEIKWAN_MACHINE:-$(uname -m 2>/dev/null || echo unknown)}"
case "$MACHINE" in
	x86_64|amd64) ARCH=amd64; PKG_ARCH=x86_64 ;;
	aarch64|arm64|armv8*) ARCH=arm64; PKG_ARCH=aarch64_generic ;;
	*) ARCH=""; PKG_ARCH="" ;;
esac

pkg_installed() {
	if [ "$PM" = apk ]; then
		apk info -e "$1" >/dev/null 2>&1
	elif [ "$PM" = opkg ]; then
		opkg status "$1" 2>/dev/null | grep -q '^Status:.* installed'
	else
		return 1
	fi
}

if command -v ucode >/dev/null 2>&1 && pkg_installed luci-base; then
	LUCI=yes
else
	LUCI=no
fi

# Package versions as the package manager knows them ("1.2.6-r1"), and as the
# binaries report them ("1.2.6"). The package database is authoritative for
# what is installed; the binary is authoritative for what is running.
pkg_version() { # pkg_version <name>
	case "$PM" in
		apk) apk info -e -v "$1" 2>/dev/null | sed -n "s/^$1-//p" | head -1 ;;
		opkg) opkg status "$1" 2>/dev/null | sed -n 's/^Version: *//p' | head -1 ;;
		*) echo "" ;;
	esac
}
binary_version() { # binary_version <path>
	[ -x "$1" ] || { echo ""; return; }
	"$1" version 2>/dev/null | head -1
}
init_enabled() { # init_enabled <script>
	[ -x "$1" ] || { echo "absent"; return; }
	"$1" enabled >/dev/null 2>&1 && echo "yes" || echo "no"
}
init_running() { # init_running <script>
	[ -x "$1" ] || { echo "absent"; return; }
	"$1" running >/dev/null 2>&1 && echo "yes" || echo "no"
}

INST_LEIKWAND=$(pkg_version leikwand)
INST_RUNTIME=$(pkg_version leikwan-runtime)
INST_LUCI=$(pkg_version luci-app-leikwan)
BIN_LEIKWAND=$(binary_version "$ROOT/usr/bin/leikwand")
BIN_RUNTIME=$(binary_version "$ROOT/usr/libexec/leikwan/ultra-client")
SVC_ENABLED=$(init_enabled "$ROOT/etc/init.d/leikwand")
SVC_RUNNING=$(init_running "$ROOT/etc/init.d/leikwand")
PANEL_URL=$(uci -q get leikwan.main.panel_url 2>/dev/null || echo "")
CLIENT_ID=$(uci -q get leikwan.main.client_id 2>/dev/null || echo "")

# --- the manifest ----------------------------------------------------------
# "<sha256>  <path under /artifacts/files/openwrt/>", one entry per package,
# format and architecture. The installer refuses an ambiguous manifest; this
# script reports the ambiguity instead of picking one.

MANIFEST=""
MANIFEST_REASON=""
if [ "$OFFLINE" = 0 ] && [ "$PANEL" != "" ]; then
	MANIFEST=$(mktemp "${TMPDIR:-/tmp}/leikwan-versions.XXXXXX") || die "cannot create a temp file"
	trap 'rm -f "$MANIFEST"' EXIT INT TERM
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --retry 2 --connect-timeout 15 -o "$MANIFEST" "$PANEL/artifacts/openwrt-packages.txt" 2>/dev/null || MANIFEST=""
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$MANIFEST" "$PANEL/artifacts/openwrt-packages.txt" 2>/dev/null || MANIFEST=""
	fi
	[ -n "$MANIFEST" ] || { rm -f "$MANIFEST"; MANIFEST=""; MANIFEST_REASON="cannot reach $PANEL"; }
fi

# manifest_pick <package> [prefix] -> "<sha256> <path>", or nothing.
# The prefix defaults to this router's format and architecture. Matching is
# the installer's: the file must sit directly in the prefix directory and be
# named <package><sep><version>.<ext>, so leikwand never matches
# leikwand-extra.
manifest_pick() {
	[ -n "$MANIFEST" ] || return 0
	if [ -n "${2:-}" ]; then
		mprefix="$2"
	else
		case "$PM" in
			apk) mprefix="apk/$ARCH/"; msep="-"; mext=apk ;;
			opkg) mprefix="$ARCH/"; msep="_"; mext=ipk ;;
			*) return 0 ;;
		esac
	fi
	[ -n "$mprefix" ] || return 0
	if [ "$mprefix" = "apk/$ARCH/" ]; then msep="-"; mext=apk
	elif [ "$mprefix" = "$ARCH/" ]; then msep="_"; mext=ipk
	else
		# An explicit prefix: infer the format from its directory name.
		case "$mprefix" in
			apk/*) msep="-"; mext=apk ;;
			*) msep="_"; mext=ipk ;;
		esac
	fi
	awk -v prefix="$mprefix" -v name="$1" -v sep="$msep" -v ext="$mext" '
		/^[ \t]*#/ || NF < 2 { next }
		index($2, prefix) == 1 {
			file = substr($2, length(prefix) + 1)
			if (index(file, "/") == 0 && index(file, name sep) == 1 &&
			    substr(file, length(name) + 2, 1) ~ /[0-9]/ &&
			    substr(file, length(file) - length(ext)) == "." ext) {
				print $1, $2; found++
			}
		}
		END { exit found == 1 ? 0 : 1 }' "$MANIFEST"
}

# The version inside a package file name: leikwand-1.2.6-r1.apk -> 1.2.6-r1,
# leikwand_1.2.6-r1_x86_64.ipk -> 1.2.6-r1.
file_version() { # file_version <package-name> <path>
	base=${2##*/}
	rest=${base#"$1"}
	# Strip the separator and, for ipk, the trailing _<arch>.
	rest=${rest#_}
	rest=${rest#-}
	case "$rest" in
		# The arch suffix can itself contain underscores (x86_64,
		# aarch64_cortex-a53), so strip the longest match, not the shortest.
		*.ipk) rest=${rest%%_*.ipk} ;;
		*.apk) rest=${rest%.apk} ;;
	esac
	echo "$rest"
}

REMOTE_LEIKWAND="" REMOTE_LEIKWAND_PATH=""
REMOTE_RUNTIME="" REMOTE_RUNTIME_PATH=""
REMOTE_LUCI="" REMOTE_LUCI_PATH=""
if [ -n "$MANIFEST" ]; then
	entry=$(manifest_pick leikwand) || entry=""
	[ -n "$entry" ] && { REMOTE_LEIKWAND=$(file_version leikwand "${entry#* }"); REMOTE_LEIKWAND_PATH=${entry#* }; }
	entry=$(manifest_pick leikwan-runtime) || entry=""
	[ -n "$entry" ] && { REMOTE_RUNTIME=$(file_version leikwan-runtime "${entry#* }"); REMOTE_RUNTIME_PATH=${entry#* }; }
	entry=$(manifest_pick luci-app-leikwan) || entry=""
	[ -n "$entry" ] && { REMOTE_LUCI=$(file_version luci-app-leikwan "${entry#* }"); REMOTE_LUCI_PATH=${entry#* }; }
fi

manifest_count() { # manifest_count [pattern]
	[ -n "$MANIFEST" ] || { echo 0; return; }
	awk -v pat="${1:-}" '/^[ \t]*#/ || NF < 2 { next } pat == "" || index($2, pat) == 1 { n++ } END { print n + 0 }' "$MANIFEST"
}

# An update is available when the panel names a file for a package this
# router runs and the installed version differs from it.
UPDATE_LEIKWAND=0 UPDATE_RUNTIME=0 UPDATE_LUCI=0
UPDATE_AVAILABLE=0
for pair in "leikwand:$INST_LEIKWAND:$REMOTE_LEIKWAND" \
            "leikwan-runtime:$INST_RUNTIME:$REMOTE_RUNTIME" \
            "luci-app-leikwan:$INST_LUCI:$REMOTE_LUCI"; do
	name=${pair%%:*}
	rest=${pair#*:}
	have=${rest%%:*}
	want=${rest#*:}
	[ -n "$want" ] || continue
	if [ -z "$have" ] || [ "$have" != "$want" ]; then
		case "$name" in
			leikwand) UPDATE_LEIKWAND=1 ;;
			leikwan-runtime) UPDATE_RUNTIME=1 ;;
			*) UPDATE_LUCI=1 ;;
		esac
		UPDATE_AVAILABLE=1
	fi
done

# --- modes ----------------------------------------------------------------

if [ "$MODE" = detect ]; then
	echo "firmware=$FIRMWARE"
	echo "release=${DISTRIB_RELEASE:-}"
	echo "target=${DISTRIB_ARCH:-}"
	echo "machine=$MACHINE"
	echo "package_manager=$PM"
	echo "format=$( [ "$PM" = apk ] && echo apk || { [ "$PM" = opkg ] && echo ipk || echo none; } )"
	echo "arch=$ARCH"
	echo "package_arch=$PKG_ARCH"
	echo "luci=$LUCI"
	echo "installed_leikwand=$INST_LEIKWAND"
	echo "installed_runtime=$INST_RUNTIME"
	exit 0
fi

if [ "$MODE" = target ]; then
	[ -n "$REMOTE_LEIKWAND_PATH" ] || die "no single leikwand entry for $( [ "$PM" = apk ] && echo "apk/$ARCH" || echo "$ARCH") in the manifest"
	echo "$REMOTE_LEIKWAND_PATH"
	exit 0
fi

if [ "$MODE" = all ]; then
	[ -n "$MANIFEST" ] || die "no manifest to list: ${MANIFEST_REASON:-offline}"
	printf '\n  %-58s %s\n' "path" "sha256"
	printf '  %s\n' "--------------------------------------------------------------"
	while read -r sum path; do
		case "$sum" in ''|'#'*) continue ;; esac
		[ -n "$path" ] || continue
		printf '  %-58s %s\n' "$path" "$sum"
	done < "$MANIFEST"
	printf '\n'
	log "$(manifest_count) entries in the manifest"
	exit 0
fi

if [ "$MODE" = check ]; then
	if [ -n "$MANIFEST_REASON" ]; then
		log "cannot compare: $MANIFEST_REASON"
		exit 2
	fi
	if [ "$UPDATE_AVAILABLE" = 1 ]; then
		[ -n "$REMOTE_LEIKWAND" ] && [ -n "$INST_LEIKWAND" ] && [ "$INST_LEIKWAND" != "$REMOTE_LEIKWAND" ] \
			&& log "leikwand $INST_LEIKWAND -> $REMOTE_LEIKWAND"
		[ -n "$REMOTE_RUNTIME" ] && [ -n "$INST_RUNTIME" ] && [ "$INST_RUNTIME" != "$REMOTE_RUNTIME" ] \
			&& log "leikwan-runtime $INST_RUNTIME -> $REMOTE_RUNTIME"
		[ -n "$REMOTE_LUCI" ] && [ -n "$INST_LUCI" ] && [ "$INST_LUCI" != "$REMOTE_LUCI" ] \
			&& log "luci-app-leikwan $INST_LUCI -> $REMOTE_LUCI"
		exit 1
	fi
	exit 0
fi

if [ "$MODE" = json ]; then
	json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
	fmt_name() { [ "$PM" = apk ] && echo apk || { [ "$PM" = opkg ] && echo ipk || echo none; }; }
	printf '{'
	printf '"firmware":"%s",' "$(json_escape "$FIRMWARE")"
	printf '"release":"%s",' "$(json_escape "${DISTRIB_RELEASE:-}")"
	printf '"package_manager":"%s",' "$PM"
	printf '"format":"%s",' "$(fmt_name)"
	printf '"machine":"%s",' "$(json_escape "$MACHINE")"
	printf '"arch":"%s",' "$ARCH"
	printf '"package_arch":"%s",' "$PKG_ARCH"
	printf '"luci":"%s",' "$LUCI"
	printf '"service_enabled":"%s",' "$SVC_ENABLED"
	printf '"service_running":"%s",' "$SVC_RUNNING"
	printf '"panel":"%s",' "$(json_escape "$PANEL")"
	if [ -n "$MANIFEST_REASON" ]; then
		printf '"manifest_error":"%s",' "$(json_escape "$MANIFEST_REASON")"
	fi
	printf '"manifest_entries":%s,' "$(manifest_count)"
	printf '"update_available":%s,' "$( [ "$UPDATE_AVAILABLE" = 1 ] && echo true || echo false )"
	printf '"packages":{'
	first=1
	for name in leikwand leikwan-runtime luci-app-leikwan; do
		case "$name" in
			leikwand) i="$INST_LEIKWAND"; b="$BIN_LEIKWAND"; r="$REMOTE_LEIKWAND"; rp="$REMOTE_LEIKWAND_PATH"; u="$UPDATE_LEIKWAND" ;;
			leikwan-runtime) i="$INST_RUNTIME"; b="$BIN_RUNTIME"; r="$REMOTE_RUNTIME"; rp="$REMOTE_RUNTIME_PATH"; u="$UPDATE_RUNTIME" ;;
			*) i="$INST_LUCI"; b=""; r="$REMOTE_LUCI"; rp="$REMOTE_LUCI_PATH"; u="$UPDATE_LUCI" ;;
		esac
		[ "$first" = 1 ] || printf ','
		first=0
		printf '"%s":{"installed":"%s","binary":"%s","published":"%s","file":"%s","update":%s}' \
			"$name" "$(json_escape "$i")" "$(json_escape "$b")" "$(json_escape "$r")" "$(json_escape "$rp")" \
			"$( [ "$u" = 1 ] && echo true || echo false )"
	done
	printf '}'
	printf '}\n'
	exit 0
fi

# --- the report ------------------------------------------------------------

fmt_name() { [ "$PM" = apk ] && echo ".apk, OpenWrt 25.12+" || { [ "$PM" = opkg ] && echo ".ipk, OpenWrt 21.02-24.10" || echo "none found"; }; }
THIS_PREFIX=$( [ "$PM" = apk ] && echo "apk/$ARCH/" || { [ "$PM" = opkg ] && echo "$ARCH/" || echo ""; } )

printf '\n'
printf '  Leikwan OpenWrt versions\n'
printf '  %s\n' "--------------------------------------------------------------"
printf '  %-22s %s\n' "firmware" "$FIRMWARE${DISTRIB_ARCH:+ ($DISTRIB_ARCH)}"
printf '  %-22s %s\n' "package manager" "$PM ($(fmt_name))"
printf '  %-22s %s\n' "CPU" "$MACHINE -> $ARCH build (package arch $PKG_ARCH)"
printf '  %-22s %s\n' "LuCI app" "$( [ "$LUCI" = yes ] && echo "installable (ucode LuCI present)" || echo "not applicable (no ucode LuCI)" )"
printf '  %-22s %s\n' "service" "enabled=$SVC_ENABLED running=$SVC_RUNNING"
[ -n "$PANEL_URL" ] && printf '  %-22s %s\n' "panel (configured)" "$PANEL_URL"
[ -n "$CLIENT_ID" ] && printf '  %-22s %s\n' "client id" "$CLIENT_ID"
printf '  %-22s %s\n' "panel (queried)" "$( [ "$OFFLINE" = 1 ] && echo "not queried (--offline)" || echo "$PANEL${MANIFEST_REASON:+ — $MANIFEST_REASON}" )"
printf '\n'
printf '  %-20s %-12s %-12s %-12s %s\n' "package" "installed" "running" "published" "state"
printf '  %s\n' "--------------------------------------------------------------"
show_pkg() { # show_pkg <name> <installed> <binary> <published> <path> <update-flag>
	state=""
	if [ -z "$5" ]; then
		state="not in the manifest"
	elif [ "$6" = 1 ]; then
		state="UPDATE -> $5"
	else
		state="up to date"
	fi
	printf '  %-20s %-12s %-12s %-12s %s\n' "$1" "${2:-—}" "${3:-—}" "${4:-—}" "$state"
}
show_pkg leikwand "$INST_LEIKWAND" "$BIN_LEIKWAND" "$REMOTE_LEIKWAND" "$REMOTE_LEIKWAND_PATH" "$UPDATE_LEIKWAND"
show_pkg leikwan-runtime "$INST_RUNTIME" "$BIN_RUNTIME" "$REMOTE_RUNTIME" "$REMOTE_RUNTIME_PATH" "$UPDATE_RUNTIME"
show_pkg luci-app-leikwan "$INST_LUCI" "" "$REMOTE_LUCI" "$REMOTE_LUCI_PATH" "$UPDATE_LUCI"
printf '\n'
if [ -n "$MANIFEST" ]; then
	printf '  manifest: %s entries, %s for this router (%s)\n' \
		"$(manifest_count)" "$(manifest_count "$THIS_PREFIX")" "$THIS_PREFIX"
fi
if [ -n "$REMOTE_LEIKWAND_PATH" ]; then
	printf '  this router installs: %s\n' "$REMOTE_LEIKWAND_PATH"
fi
if [ "$UPDATE_AVAILABLE" = 1 ]; then
	printf '\n  An update is published. To apply it:\n'
	printf '    sh install-openwrt.sh --panel %s\n' "$PANEL"
elif [ -n "$MANIFEST_REASON" ]; then
	printf '\n  Only the local side is known.\n'
elif [ -z "$MANIFEST" ]; then
	printf '\n  Only the local side is known (--offline).\n'
else
	printf '\n  Nothing to change.\n'
fi
printf '\n'
exit 0
