#!/bin/bash
set -uo pipefail
export LC_ALL=C
KEY=~/.ssh/leikwan_macos_build
HOST=leikwan@100.103.253.21
OPTS="-i $KEY -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"
REPO=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
scp $OPTS $REPO/tmp/ultra-e2e/mac-attest-probe.sh $HOST:~/mac-attest-probe.sh >/dev/null
ssh $OPTS $HOST 'bash ~/mac-attest-probe.sh' 2>&1
echo "RC=$?"
