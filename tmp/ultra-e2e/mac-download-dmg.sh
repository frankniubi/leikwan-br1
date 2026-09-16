#!/bin/bash
set -euo pipefail
export LC_ALL=C
KEY=~/.ssh/leikwan_macos_build
HOST=leikwan@100.103.253.21
OPTS="-i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"
for arch in arm64 x64; do
  scp $OPTS "$HOST:~/lkb-smoke/$arch-1.0.32.dmg" "/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.build/mac-1032/out/leikwan-bridge-$arch.dmg"
done
ls -l /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.build/mac-1032/out/
sha256sum /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.build/mac-1032/out/*.dmg
echo DOWNLOAD-DONE
