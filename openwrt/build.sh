#!/bin/sh
# Build the Leikwan OpenWrt packages (ipk) for one target architecture.
#
# Usage:  ./build.sh <amd64|arm64> [sdk-dir]
#
# The script cross-compiles the two Go programs with CGO_ENABLED=0, stages them
# into the package directories, then hands off to the OpenWrt SDK. It never
# modifies the SDK tree outside build_dir/staging_dir/bin.
set -eu

ARCH="${1:-amd64}"
case "$ARCH" in
	amd64|x86_64) GOARCH=amd64 ;;
	arm64|aarch64) GOARCH=arm64 ;;
	*) echo "unsupported architecture '$ARCH' (expected amd64 or arm64)" >&2; exit 2 ;;
esac

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/.." && pwd)
SDK="${2:-${LEIKWAN_SDK:-$HOME/leikwan-build/sdk}}"

if [ ! -f "$SDK/rules.mk" ]; then
	echo "OpenWrt SDK not found at $SDK (set LEIKWAN_SDK or pass it as $2)" >&2
	exit 2
fi

echo "==> building leikwand for linux/$GOARCH"
mkdir -p "$HERE/leikwand/bin"
( cd "$REPO/tools/leikwand" && \
	CGO_ENABLED=0 GOOS=linux GOARCH="$GOARCH" \
	go build -trimpath -ldflags "-s -w -X main.agentVersion=$VERSION" \
	-o "$HERE/leikwand/bin/leikwand" . )

echo "==> building ultra-client runtime for linux/$GOARCH"
mkdir -p "$HERE/leikwan-runtime/bin"
( cd "$REPO/tools/ultra-client" && \
	CGO_ENABLED=0 GOOS=linux GOARCH="$GOARCH" \
	go build -trimpath -ldflags "-s -w -X main.runtimeBuildVersion=${VERSION:-1.0.0}" \
	-o "$HERE/leikwan-runtime/bin/ultra-client" . )

echo "==> verifying the staged binaries are static"
for bin in "$HERE/leikwand/bin/leikwand" "$HERE/leikwan-runtime/bin/ultra-client"; do
	if command -v file >/dev/null 2>&1; then file "$bin"; fi
done

echo "==> staging packages into the SDK"
rm -rf "$SDK/package/leikwan"
mkdir -p "$SDK/package/leikwan"
for pkg in leikwand leikwan-runtime luci-app-leikwan; do
	cp -a "$HERE/$pkg" "$SDK/package/leikwan/"
done

echo "==> building ipk with the SDK"
cd "$SDK"
make defconfig >/dev/null
make package/leikwan/leikwand/compile V=s
make package/leikwan/leikwan-runtime/compile V=s
make package/leikwan/luci-app-leikwan/compile V=s

echo "==> packages"
find "$SDK/bin" -name '*.ipk' -newermt '-1 hour' -print | sort
