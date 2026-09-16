#!/bin/bash
set -uo pipefail
export LC_ALL=C
KEY=~/.ssh/leikwan_macos_build
HOST=leikwan@100.103.253.21
SSHOPTS="-i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"
scp $SSHOPTS /mnt/c/Users/frankniubi/Downloads/leikwan-br1/tmp/ultra-e2e/mac-dmg-1032.sh $HOST:~/mac-dmg-1032.sh
ssh $SSHOPTS $HOST 'rm -rf ~/lkb-smoke/b31-* ~/lkb-smoke/b32-* ~/lkb-smoke/clean-* ~/lkb-smoke/*.dmg ~/lkb-smoke/*.zip.tmp; df -h ~ | tail -1; bash ~/mac-dmg-1032.sh'
echo MAC-RUNNER-DONE
