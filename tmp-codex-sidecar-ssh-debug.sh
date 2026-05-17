#!/usr/bin/env bash
set +e
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-vvv -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o PreferredAuthentications=password,publickey)
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" -J root@$SLAVE_HOST root@185.220.238.143 'echo SIDE_OK; hostname; date -Is' 2>&1 | tail -n 120
