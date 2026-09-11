#!/bin/bash
# Build a pre-installed OpenWrt firmware image that already contains the
# Leikwan convergence app, so a router can be flashed and come up enrolled-
# ready without an opkg step.
#
# Usage: ./build-image.sh <amd64|arm64> [imagebuilder-dir]
#
# Required: the three ipk packages must already have been built (see
# ../build-ipk.sh); pass their directory through LEIKWAN_IPK_DIR or let this
# script look in the SDK's bin/packages output.
set -euo pipefail

ARCH="${1:-amd64}"
case "$ARCH" in
	amd64|x86_64) PROFILE=generic ;;
	arm64|aarch64) PROFILE=generic ;;
	*) echo "unsupported architecture '$ARCH'" >&2; exit 2 ;;
esac

HERE=$(cd "$(dirname "$0")" && pwd)
IB="${2:-${LEIKWAN_IB:-$HOME/leikwan-build/ib}}"
IPK_DIR="${LEIKWAN_IPK_DIR:-}"

if [ ! -f "$IB/Makefile" ]; then
	echo "OpenWrt ImageBuilder not found at $IB" >&2
	exit 2
fi

# Locate the packages we just built.
if [ -z "$IPK_DIR" ]; then
	for candidate in "$HOME/leikwan-build/sdk/bin/packages"/*/base; do
		if ls "$candidate"/leikwand_*.ipk >/dev/null 2>&1; then IPK_DIR="$candidate"; break; fi
	done
fi
if [ -z "$IPK_DIR" ] || ! ls "$IPK_DIR"/leikwand_*.ipk >/dev/null 2>&1; then
	echo "leikwand ipk not found; run ../build-ipk.sh first or set LEIKWAN_IPK_DIR" >&2
	exit 2
fi
echo "==> using packages from $IPK_DIR"

# ImageBuilder only sees local packages through a repository entry, so publish
# the build output as one and refresh the index.
# ImageBuilder already declares its own packages/ directory as a repository
# ("file:packages"). That is the only directory its package_index target
# indexes, so the built ipks are staged there rather than in a new repo.
LOCAL_REPO="$IB/packages"
rm -rf "$LOCAL_REPO"
mkdir -p "$LOCAL_REPO"
for pattern in 'leikwand_*.ipk' 'leikwan-runtime_*.ipk' 'luci-app-leikwan_*.ipk'; do
	matched=0
	for file in "$IPK_DIR"/$pattern; do
		[ -f "$file" ] || continue
		cp "$file" "$LOCAL_REPO/"
		matched=1
	done
	if [ "$matched" = "0" ]; then
		echo "required package $pattern not found in $IPK_DIR" >&2
		exit 2
	fi
done
ls -1 "$LOCAL_REPO"/*.ipk

# Restore the stock repository list. ImageBuilder appends its own local
# "file:packages" entry while generating the opkg config, so a repositories.conf
# that accumulated entries across runs produces duplicate src declarations and
# makes opkg abort the package list.
if [ -f "$IB/repositories.conf.example" ]; then
	cp "$IB/repositories.conf.example" "$IB/repositories.conf"
fi

# The local index is signed, and opkg verifies it against the image keyring.
# A freshly unpacked ImageBuilder ships no key pair and no keyring entry, so
# both are created here; without them the build fails with an opaque
# "Signature check failed" followed by "Cannot install package leikwand".
USIGN="$IB/staging_dir/host/bin/usign"
if [ -x "$USIGN" ]; then
	if [ ! -f "$IB/key-build" ]; then
		( cd "$IB" && "$USIGN" -G -s key-build -p key-build.pub -c "leikwan image build" ) >/dev/null 2>&1 || true
	fi
	if [ -f "$IB/key-build.pub" ]; then
		# The keyring filename is the key fingerprint, which is what opkg
		# matches the signature against.
		FINGERPRINT=$("$USIGN" -F -p "$IB/key-build.pub" 2>/dev/null | awk '{print $1}')
		if [ -n "$FINGERPRINT" ]; then
			mkdir -p "$IB/keys"
			cp "$IB/key-build.pub" "$IB/keys/$FINGERPRINT"
			echo "==> trusting the build key $FINGERPRINT"
		fi
	fi
fi

# opkg needs the generated index next to the ipks. The step is checked: a
# silent failure here shows up much later as "Cannot install package leikwand".
( cd "$IB" && make package_index ) || {
	echo "could not generate the local package index" >&2
	exit 2
}
if [ ! -f "$LOCAL_REPO/Packages" ] && [ ! -f "$LOCAL_REPO/Packages.gz" ]; then
	echo "local package index is missing from $LOCAL_REPO" >&2
	exit 2
fi

# UCI defaults baked into the image. Only onboarding-neutral values: the panel
# URL and the fact that the service is enabled. Nothing secret ever ships in a
# firmware image.
FILES="$HERE/files"
mkdir -p "$FILES/etc/uci-defaults"
cat > "$FILES/etc/uci-defaults/99-leikwan-image" <<'EOF'
#!/bin/sh
# Pre-seed the panel URL so a freshly flashed router only needs a pairing code.
uci -q get leikwan.main >/dev/null 2>&1 || uci -q set leikwan.main=leikwan
uci -q set leikwan.main.enabled='1'
uci -q set leikwan.main.mode='forward'
uci -q commit leikwan
/etc/init.d/leikwand enable >/dev/null 2>&1
exit 0
EOF
chmod +x "$FILES/etc/uci-defaults/99-leikwan-image"

# luci-ssl-openssl is deliberately NOT listed: it conflicts with the default
# libustream-mbedtls that the base image already carries.
PACKAGES="luci luci-ssl leikwand leikwan-runtime luci-app-leikwan"

# ImageBuilder shells out to find(1) with -execdir, which refuses to run when
# PATH contains a relative or whitespace-bearing entry. That happens when the
# build runs inside WSL with the Windows PATH inherited, so the entries that
# trip it are stripped here rather than left for the operator to discover.
CLEAN_PATH=""
OLD_IFS="$IFS"; IFS=':'
for entry in $PATH; do
	case "$entry" in
		/*) case "$entry" in *" "*) ;; *) CLEAN_PATH="${CLEAN_PATH:+$CLEAN_PATH:}$entry" ;; esac ;;
		*) ;;
	esac
done
IFS="$OLD_IFS"
[ -n "$CLEAN_PATH" ] && export PATH="$CLEAN_PATH"

echo "==> building the firmware image (profile $PROFILE)"
cd "$IB"
# Piping straight into tail hides the line that explains a failure, which is
# exactly the line the operator needs. Keep the whole log and surface the
# diagnostic lines on failure.
BUILD_LOG="${LEIKWAN_BUILD_LOG:-$IB/leikwan-image-build.log}"
if ! make image PROFILE="$PROFILE" PACKAGES="$PACKAGES" FILES="$FILES" \
	EXTRA_IMAGE_NAME="leikwan-${LEIKWAND_VERSION:-1.0.0}" >"$BUILD_LOG" 2>&1; then
	echo "firmware build failed; diagnostics from $BUILD_LOG:" >&2
	grep -E 'Cannot install|check_data_file_clashes|Collected errors|^ \*|No such file|unknown package' "$BUILD_LOG" | head -20 >&2
	tail -15 "$BUILD_LOG" >&2
	exit 1
fi
tail -5 "$BUILD_LOG"

echo "==> firmware images"
find "$IB/bin/targets" -type f \( -name '*.img.gz' -o -name '*.img' -o -name '*.bin' \) -print | sort
