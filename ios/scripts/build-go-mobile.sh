#!/usr/bin/env bash
# Builds ios/Frameworks/Libbox.xcframework: sing-box's libbox plus the LeiKwan
# ultra runtime (package lkmobile), bound together by gomobile so the packet
# tunnel process hosts a single Go runtime.
#
# Requires macOS with Xcode and Go. Environment overrides:
#   SING_BOX_VERSION  sing-box tag (default v1.13.12, same as the macOS client)
#   GOMOBILE_VERSION  sagernet/gomobile version pinned by that sing-box tag
#   GOMOBILE_TARGETS  gomobile -target value (default ios,iossimulator)
#   ULTRA_VERSION     fingerprint stamped into the ultra runtime
set -euo pipefail

SING_BOX_VERSION="${SING_BOX_VERSION:-v1.13.12}"
GOMOBILE_VERSION="${GOMOBILE_VERSION:-v0.1.12}"
GOMOBILE_TARGETS="${GOMOBILE_TARGETS:-ios,iossimulator}"
ULTRA_VERSION="${ULTRA_VERSION:-ios-development}"

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
work="${WORK_DIR:-$repo_root/ios/.build/go-mobile}"
output="$repo_root/ios/Frameworks/Libbox.xcframework"
sing_box="$work/sing-box"

fail() { echo "build-go-mobile: $*" >&2; exit 1; }

rm -rf "$work"
mkdir -p "$work" "$repo_root/ios/Frameworks"
git clone --quiet --depth 1 --branch "$SING_BOX_VERSION" https://github.com/SagerNet/sing-box.git "$sing_box"

# The ultra runtime is a main package in tools/ultra-client; copy its non-test
# sources into the sing-box module as an importable package. The OpenGFW
# analyzers it uses are stdlib-only, so they are copied alongside instead of
# pulling OpenGFW's whole module graph into sing-box's go.mod.
leikwan="$sing_box/leikwan"
mkdir -p "$leikwan/ultracore" "$leikwan/lkmobile"
rewrite_imports='s#"github.com/apernet/OpenGFW/#"github.com/sagernet/sing-box/leikwan/opengfw/#g'
for source in "$repo_root"/tools/ultra-client/*.go; do
  case "$source" in *_test.go) continue ;; esac
  sed -e 's/^package main$/package ultracore/' -e "$rewrite_imports" "$source" > "$leikwan/ultracore/$(basename "$source")"
done
if grep -L '^package ultracore$' "$leikwan"/ultracore/*.go | grep -q .; then
  fail "ultra sources without a rewritable package clause"
fi
for package in analyzer analyzer/tcp analyzer/utils; do
  mkdir -p "$leikwan/opengfw/$package"
  for source in "$repo_root/opengfw/$package"/*.go; do
    case "$source" in *_test.go) continue ;; esac
    sed -e "$rewrite_imports" "$source" > "$leikwan/opengfw/$package/$(basename "$source")"
  done
done
cp "$repo_root"/ios/runtime/go/lkmobile/*.go "$leikwan/lkmobile/"

cd "$sing_box"
go install "github.com/sagernet/gomobile/cmd/gomobile@$GOMOBILE_VERSION"
go install "github.com/sagernet/gomobile/cmd/gobind@$GOMOBILE_VERSION"
export PATH="$(go env GOPATH)/bin:$PATH"

# Fail fast on a compile error in the copied packages before the long bind.
GOOS=ios GOARCH=arm64 CGO_ENABLED=1 CC="$(xcrun --sdk iphoneos --find clang)" \
  CGO_CFLAGS="-isysroot $(xcrun --sdk iphoneos --show-sdk-path) -arch arm64 -miphoneos-version-min=16.0" \
  CGO_LDFLAGS="-isysroot $(xcrun --sdk iphoneos --show-sdk-path) -arch arm64 -miphoneos-version-min=16.0" \
  go vet -tags "with_gvisor,with_low_memory,badlinkname,tfogo_checklinkname0" ./leikwan/...

# Tags: only what the iOS client needs (tun via gVisor, shadowsocks and socks
# are always built in). with_low_memory matches upstream's iOS build.
# with_clash_api: sing-box requires the Clash API whenever the platform passes a log
# writer (libbox sets PlatformLogWriter), which the packet tunnel does.
tags="with_gvisor,with_low_memory,with_clash_api,badlinkname,tfogo_checklinkname0,grpcnotrace"
version="${SING_BOX_VERSION#v}"
ldflags="-X github.com/sagernet/sing-box/constant.Version=$version"
ldflags="$ldflags -X github.com/sagernet/sing-box/leikwan/ultracore.runtimeBuildVersion=$ULTRA_VERSION"
ldflags="$ldflags -X internal/godebug.defaultGODEBUG=multipathtcp=0 -s -w -buildid= -checklinkname=0"

gomobile bind -v \
  -target "$GOMOBILE_TARGETS" \
  -iosversion 16.0 \
  -libname=box \
  -o "$work/Libbox.xcframework" \
  -trimpath -buildvcs=false \
  -ldflags "$ldflags" \
  -tags "$tags" \
  ./experimental/libbox ./leikwan/lkmobile

rm -rf "$output"
mv "$work/Libbox.xcframework" "$output"
echo "build-go-mobile: wrote $output"
