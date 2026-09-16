#!/bin/bash
set -uo pipefail
export LC_ALL=C
KEY=~/.ssh/leikwan_macos_build
HOST=leikwan@100.103.253.21
OPTS="-i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"
REPO=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
for arch in arm64 x64; do
  scp $OPTS "$REPO/.build/mac-1033/out/leikwan-bridge-update-$arch.zip" "$HOST:~/lkb-smoke/u33-$arch.zip" >/dev/null
done
scp $OPTS $REPO/tmp/ultra-e2e/mac-dmg-1033.sh $HOST:~/mac-dmg-1033.sh >/dev/null
ssh $OPTS $HOST 'bash ~/mac-dmg-1033.sh' 2>&1
for arch in arm64 x64; do
  scp $OPTS "$HOST:~/lkb-smoke/$arch-1.0.33.dmg" "$REPO/.build/mac-1033/out/leikwan-bridge-$arch.dmg" >/dev/null
done
echo "--- downloaded ---"
sha256sum $REPO/.build/mac-1033/out/*.dmg
echo MAC-1033-RUNNER-DONE
