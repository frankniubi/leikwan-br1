#!/usr/bin/env bash
set -x
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-vvv -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=12 -o PreferredAuthentications=password -o PubkeyAuthentication=no)
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" -J root@$SLAVE_HOST root@185.220.238.143 'echo hello; hostname; exit' 
rc=$?; echo rc=$rc
