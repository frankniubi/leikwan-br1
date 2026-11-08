#!/bin/sh
# One installer for every supported OpenWrt release.
#
# It detects what the router runs and installs the matching Leikwan packages:
#   - package manager: apk (OpenWrt 25.12+) or opkg (21.02 - 24.10, ImmortalWrt,
#     GL.iNet and other opkg firmwares);
#   - CPU family: x86_64 (amd64 build) or aarch64 (arm64 build). The binaries are
#     static, so one build serves every CPU of the family;
#   - LuCI: the web app needs ucode-based LuCI (23.05+). Older firmwares and
#     headless images get the agent and the runtime only, and are managed with
#     `leikwand ctl` over SSH.
#
# Every package is checked against the panel's SHA-256 manifest before anything
# is installed. With --client-id and --agent-token the router is registered to
# that client once the service is up.
#
# Usage:
#   wget -qO /tmp/leikwan-install.sh https://v3.leikwanhost.com/artifacts/install-openwrt.sh
#   sh /tmp/leikwan-install.sh --client-id <id> --agent-token <token>
#
# Runs under BusyBox ash: no bash features.

set -u

PANEL="${LEIKWAN_PANEL:-https://v3.leikwanhost.com}"
CLIENT_ID=""
AGENT_TOKEN=""
LUCI="auto"
DRY_RUN=0
# Test hooks: the unit test points these at a scratch tree.
ROOT="${LEIKWAN_ROOT:-}"
RUN_DIR="${LEIKWAN_RUN_DIR:-$ROOT/var/run/leikwand}"
WAIT_SECONDS="${LEIKWAN_REGISTER_WAIT:-60}"

log() { echo "leikwan-install: $*"; }
die() { echo "leikwan-install: ERROR: $*" >&2; exit 1; }

usage() {
	cat <<'EOF'
Usage: install-openwrt.sh [options]

  --client-id <id>        register the router to this client after install
  --agent-token <token>   agent token shown with the client in the panel
  --panel <url>           panel address (default https://v3.leikwanhost.com)
  --luci | --no-luci      force the LuCI app on or off (default: auto-detect)
  --dry-run               detect and print the plan, change nothing
  --help
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--client-id) [ "$#" -ge 2 ] || die "--client-id needs a value"; CLIENT_ID="$2"; shift 2 ;;
		--agent-token) [ "$#" -ge 2 ] || die "--agent-token needs a value"; AGENT_TOKEN="$2"; shift 2 ;;
		--panel) [ "$#" -ge 2 ] || die "--panel needs a value"; PANEL="$2"; shift 2 ;;
		--luci) LUCI="yes"; shift ;;
		--no-luci) LUCI="no"; shift ;;
		--dry-run) DRY_RUN=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; die "unknown option: $1" ;;
	esac
done
PANEL="${PANEL%/}"

if [ -n "$CLIENT_ID" ] || [ -n "$AGENT_TOKEN" ]; then
	case "$CLIENT_ID" in
		''|*[!0-9]*) die "--client-id must be a number" ;;
	esac
	[ -n "$AGENT_TOKEN" ] || die "--agent-token is required with --client-id"
fi

# --- detection -------------------------------------------------------------

RELEASE_FILE="$ROOT/etc/openwrt_release"
[ -f "$RELEASE_FILE" ] || die "this does not look like OpenWrt ($RELEASE_FILE is missing)"
DISTRIB_ID="" DISTRIB_RELEASE="" DISTRIB_ARCH=""
# shellcheck disable=SC1090
. "$RELEASE_FILE"
FIRMWARE="${DISTRIB_ID:-OpenWrt} ${DISTRIB_RELEASE:-unknown}"

# The package database decides, not which binaries happen to exist: a firmware
# owns exactly one of them.
if [ -f "$ROOT/etc/apk/world" ] && command -v apk >/dev/null 2>&1; then
	PM=apk
elif [ -d "$ROOT/usr/lib/opkg" ] && command -v opkg >/dev/null 2>&1; then
	PM=opkg
elif command -v apk >/dev/null 2>&1; then
	PM=apk
elif command -v opkg >/dev/null 2>&1; then
	PM=opkg
else
	die "neither apk nor opkg was found on $FIRMWARE"
fi

MACHINE="${LEIKWAN_MACHINE:-$(uname -m)}"
case "$MACHINE" in
	x86_64|amd64) ARCH=amd64; PKG_ARCH=x86_64 ;;
	aarch64|arm64|armv8*) ARCH=arm64; PKG_ARCH=aarch64_generic ;;
	*) die "CPU '$MACHINE' (${DISTRIB_ARCH:-unknown}) is not supported; Leikwan builds exist for x86_64 and aarch64 routers" ;;
esac

pkg_installed() {
	if [ "$PM" = apk ]; then
		apk info -e "$1" >/dev/null 2>&1
	else
		opkg status "$1" 2>/dev/null | grep -q '^Status:.* installed'
	fi
}

# The LuCI app is a ucode rpcd backend plus JS views: it needs ucode (23.05+)
# and an installed LuCI. Anything else gets the command-line journey.
if [ "$LUCI" = auto ]; then
	if command -v ucode >/dev/null 2>&1 && pkg_installed luci-base; then
		LUCI=yes
	else
		LUCI=no
	fi
fi

log "firmware: $FIRMWARE (${DISTRIB_ARCH:-?})"
log "package manager: $PM, build: $ARCH, LuCI app: $LUCI"

# --- download --------------------------------------------------------------

fetch() { # fetch <url> <file>
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --retry 2 --connect-timeout 15 -o "$2" "$1" && return 0
	fi
	if command -v wget >/dev/null 2>&1; then
		wget -q -O "$2" "$1" && return 0
	fi
	if command -v uclient-fetch >/dev/null 2>&1; then
		uclient-fetch -q -O "$2" "$1" && return 0
	fi
	return 1
}

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	elif command -v openssl >/dev/null 2>&1; then
		openssl dgst -sha256 "$1" | sed 's/.*= *//'
	else
		die "no sha256sum or openssl on this router; cannot verify the packages"
	fi
}

WORK=$(mktemp -d "${TMPDIR:-/tmp}/leikwan-install.XXXXXX") || die "cannot create a work directory"
trap 'rm -rf "$WORK"' EXIT INT TERM

fetch "$PANEL/artifacts/openwrt-packages.txt" "$WORK/manifest" \
	|| die "cannot download the package manifest from $PANEL"

# Manifest lines are "<sha256>  <path>", the path relative to
# $PANEL/artifacts/files/openwrt/. apk packages live under apk/<arch>/ and are
# named name-<version>.apk; ipk packages live under <arch>/ as
# name_<version>_<arch>.ipk.
if [ "$PM" = apk ]; then
	PREFIX="apk/$ARCH/"; SEP="-"; EXT=apk
else
	PREFIX="$ARCH/"; SEP="_"; EXT=ipk
fi

pick() { # pick <package-name> -> "<sha256> <path>"
	awk -v prefix="$PREFIX" -v name="$1" -v sep="$SEP" -v ext="$EXT" '
		/^[ 	]*#/ || NF < 2 { next }
		index($2, prefix) == 1 {
			file = substr($2, length(prefix) + 1)
			if (index(file, "/") == 0 && index(file, name sep) == 1 &&
			    substr(file, length(name) + 2, 1) ~ /[0-9]/ &&
			    substr(file, length(file) - length(ext)) == "." ext) {
				print $1, $2; found++
			}
		}
		END { exit found == 1 ? 0 : 1 }' "$WORK/manifest"
}

PACKAGES="leikwand leikwan-runtime"
[ "$LUCI" = yes ] && PACKAGES="$PACKAGES luci-app-leikwan"

FILES=""
for name in $PACKAGES; do
	entry=$(pick "$name") || die "the manifest has no single $EXT entry for $name ($PREFIX)"
	sum=${entry%% *}
	path=${entry#* }
	file=${path##*/}
	if [ "$DRY_RUN" = 1 ]; then
		log "would install $path"
		continue
	fi
	log "downloading $file"
	# A package is immutable once published, but the CDN in front of the panel
	# caches for a year and an edge that fetched a file mid-upload keeps
	# serving the truncated copy long after the origin is fixed. The hash is
	# pinned by the manifest, so asking for a distinct URL costs nothing and
	# keeps a poisoned edge entry from breaking the install.
	fetch "$PANEL/artifacts/files/openwrt/$path?v=$sum" "$WORK/$file" || die "download failed: $path"
	got=$(sha256_of "$WORK/$file")
	[ "$got" = "$sum" ] || die "checksum mismatch for $file (expected $sum, got $got)"
	FILES="$FILES $WORK/$file"
done

if [ "$DRY_RUN" = 1 ]; then
	log "dry run: nothing installed"
	exit 0
fi

# --- install ---------------------------------------------------------------

# The arm64 packages are built for aarch64_generic, while most aarch64 routers
# declare a CPU-specific architecture (aarch64_cortex-a53, ...). Both package
# managers refuse a package whose architecture the firmware does not list. The
# binaries are static and run on every aarch64 CPU, so the package architecture
# is added to the firmware's list — persistently, because the agent's own
# self-update installs the next release the same way. Verified on stock images:
#   - apk:  an extra line in /etc/apk/arch (`apk --arch` does not help: it
#           still rejects the file);
#   - opkg: /etc/opkg/leikwan-arch.conf repeating the firmware's list plus the
#           package arch. Any `arch` line replaces opkg's built-in list, which is
#           why the existing entries are carried over (`--add-arch` alone drops
#           them and makes every installed package invalid).
if [ "$PM" = apk ]; then
	ARCH_FILE="$ROOT/etc/apk/arch"
	if [ -f "$ARCH_FILE" ] && ! grep -qx "$PKG_ARCH" "$ARCH_FILE"; then
		log "firmware architecture is $(tr '\n' ' ' < "$ARCH_FILE"); adding $PKG_ARCH to $ARCH_FILE"
		echo "$PKG_ARCH" >> "$ARCH_FILE" || die "cannot update $ARCH_FILE"
	fi
	# shellcheck disable=SC2086
	apk add --allow-untrusted $FILES || die "apk could not install the packages"
else
	ARCHES=$(opkg print-architecture 2>/dev/null)
	if ! echo "$ARCHES" | awk '{print $2}' | grep -qx "$PKG_ARCH"; then
		ARCH_CONF="$ROOT/etc/opkg/leikwan-arch.conf"
		log "firmware architectures: $(echo "$ARCHES" | awk '{printf "%s ", $2}'); adding $PKG_ARCH in $ARCH_CONF"
		mkdir -p "$ROOT/etc/opkg"
		{
			echo "# Added by the Leikwan installer: the aarch64 agent is built for $PKG_ARCH."
			echo "$ARCHES" | grep '^arch '
			echo "arch $PKG_ARCH 5"
		} > "$ARCH_CONF" || die "cannot write $ARCH_CONF"
	fi
	# shellcheck disable=SC2086
	opkg install $FILES || die "opkg could not install the packages"
fi

# The package's postinst enables and starts the service. Make sure of it: an
# upgrade over a stopped daemon would otherwise leave it stopped.
if [ -x "$ROOT/etc/init.d/leikwand" ]; then
	"$ROOT/etc/init.d/leikwand" enable >/dev/null 2>&1
	"$ROOT/etc/init.d/leikwand" running >/dev/null 2>&1 || "$ROOT/etc/init.d/leikwand" start >/dev/null 2>&1
fi
log "installed: $(leikwand version 2>/dev/null | head -1)"

# --- register --------------------------------------------------------------

if [ -n "$CLIENT_ID" ]; then
	waited=0
	while [ ! -e "$RUN_DIR/ctl.sock" ] && [ "$waited" -lt "$WAIT_SECONDS" ]; do
		sleep 1; waited=$((waited + 1))
	done
	[ -e "$RUN_DIR/ctl.sock" ] || die "leikwand did not start; check 'logread -e leikwand'"
	result=$(leikwand ctl register "client_id=$CLIENT_ID" "agent_token=$AGENT_TOKEN" 2>&1)
	case "$result" in
		*'"ok":true'*) log "registered to client $CLIENT_ID" ;;
		*) die "registration failed: $result" ;;
	esac
fi

if [ "$LUCI" = yes ]; then
	log "done. Open LuCI -> Services -> Leikwan to see the forwards."
else
	log "done. Check the service with: leikwand ctl status"
fi
