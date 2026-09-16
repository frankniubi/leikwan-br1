#!/bin/bash
set -euo pipefail
export LC_ALL=C
REPO=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
KEY=~/.ssh/leikwan_macos_build
HOST=leikwan@100.103.253.21
SSH="ssh -i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"
$SSH $HOST 'mkdir -p ~/lkb-smoke && df -h ~ | tail -1'
for arch in arm64 x64; do
  scp -i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15 \
    "$REPO/.build/mac-1032/out/leikwan-bridge-update-$arch.zip" "$HOST:~/lkb-smoke/u32-$arch.zip"
done
$SSH $HOST 'ls -l ~/lkb-smoke/u32-*.zip'
echo UPLOAD-DONE
