#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
export SSHPASS='Frank20021212.'
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=12 root@114.80.33.50 'bash -s' < tmp-codex-resume-f47-state.sh
