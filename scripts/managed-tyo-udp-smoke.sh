#!/usr/bin/env bash
set -euo pipefail

: "${SH36_HOST:=sh36.ctyun.mcbar.cc}"
: "${SH36_USER:=root}"
: "${SH36_PASS:?set SH36_PASS from your local credential file before running}"
: "${SSH_BIN:=ssh}"
: "${SSHPASS_BIN:=sshpass}"
: "${SSH_OPTS:=-o StrictHostKeyChecking=accept-new -o ServerAliveInterval=15 -o ServerAliveCountMax=3}"
: "${LISTEN_PORT:=12022}"
: "${IPERF_TARGET:=127.0.0.1}"
: "${IPERF_SECONDS:=5}"
: "${SIDECAR_RESTART_VIA_SH36_CMD:=}"
: "${SIDECAR_AUDIT_VIA_SH36_CMD:=}"

run_sh36() {
  local remote_cmd="$1"
  "$SSHPASS_BIN" -p "$SH36_PASS" \
    "$SSH_BIN" $SSH_OPTS "${SH36_USER}@${SH36_HOST}" "$remote_cmd"
}

run_sh36 "
  set -eu
  systemctl is-active lgtcp-${LISTEN_PORT}.service >/dev/null
  systemctl is-enabled lgtcp-${LISTEN_PORT}.service >/dev/null
  systemctl is-active leigod-tcp-forwards-reconcile.path >/dev/null
  systemctl is-active leigod-tcp-forwards-reconcile.timer >/dev/null
  systemctl is-enabled leigod-tcp-forwards-reconcile.path >/dev/null
  systemctl is-enabled leigod-tcp-forwards-reconcile.timer >/dev/null
  systemctl is-active lgfwd.service >/tmp/lgfwd.active 2>&1 && exit 1 || true
  ss -ltnp | grep ':${LISTEN_PORT}' | grep -E 'gost|lgtcp|tcp-forward'
"

run_sh36 "
  set -eu
  before_pid=\$(systemctl show -p MainPID --value lgtcp-${LISTEN_PORT}.service)
  /usr/bin/python3 /opt/leigod/scripts/reconcile-tcp-forwards.py >/tmp/managed-tyo-reconcile-${LISTEN_PORT}.out
  after_pid=\$(systemctl show -p MainPID --value lgtcp-${LISTEN_PORT}.service)
  test \"\$before_pid\" = \"\$after_pid\"
  grep \"unchanged lgtcp-${LISTEN_PORT}.service\" /tmp/managed-tyo-reconcile-${LISTEN_PORT}.out
"

if [[ -n "${SIDECAR_RESTART_VIA_SH36_CMD}" ]]; then
  run_sh36 "
    set -eu
    ${SIDECAR_RESTART_VIA_SH36_CMD}
  "
fi

if [[ -n "${SIDECAR_AUDIT_VIA_SH36_CMD}" ]]; then
  run_sh36 "
    set -eu
    ${SIDECAR_AUDIT_VIA_SH36_CMD}
  "
fi

run_sh36 "
  set -eu
  timeout 25 iperf3 -c ${IPERF_TARGET} -p ${LISTEN_PORT} -t ${IPERF_SECONDS} --forceflush >/tmp/managed-tyo-${LISTEN_PORT}.out 2>&1
  grep 'receiver' /tmp/managed-tyo-${LISTEN_PORT}.out
"

echo 'managed TYO smoke PASS'
