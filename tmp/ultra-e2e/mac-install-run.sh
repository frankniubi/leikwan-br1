#!/bin/bash
set -uo pipefail
export LC_ALL=C
KEY=~/.ssh/leikwan_macos_build
HOST=leikwan@100.103.253.21
OPTS="-i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"
scp $OPTS /mnt/c/Users/frankniubi/Downloads/leikwan-br1/tmp/ultra-e2e/mac-install-1032.sh $HOST:~/mac-install-1032.sh
ssh $OPTS $HOST 'bash ~/mac-install-1032.sh' 2>&1
echo "RC=$?"
