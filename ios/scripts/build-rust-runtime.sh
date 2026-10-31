#!/usr/bin/env bash
# Builds ios/Frameworks/LeikwanRuntime.xcframework: the leikwan ingress and
# bond runtimes as one static library with a C ABI (ios/runtime/rust).
#
# Requires macOS with Xcode and rustup.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
crate="$repo_root/ios/runtime/rust"
output="$repo_root/ios/Frameworks/LeikwanRuntime.xcframework"
targets=(aarch64-apple-ios aarch64-apple-ios-sim)

rustup target add "${targets[@]}"

export IPHONEOS_DEPLOYMENT_TARGET=16.0
for target in "${targets[@]}"; do
  cargo build --manifest-path "$crate/Cargo.toml" --release --locked --target "$target"
done

rm -rf "$output"
mkdir -p "$repo_root/ios/Frameworks"
xcodebuild -create-xcframework \
  -library "$crate/target/aarch64-apple-ios/release/libleikwan_ios_runtime.a" -headers "$crate/include" \
  -library "$crate/target/aarch64-apple-ios-sim/release/libleikwan_ios_runtime.a" -headers "$crate/include" \
  -output "$output"
echo "build-rust-runtime: wrote $output"
