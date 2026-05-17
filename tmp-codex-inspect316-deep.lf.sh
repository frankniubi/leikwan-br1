#!/usr/bin/env bash
set -euo pipefail
sshpass -p '@XwDQ6H5mgMXbrGD' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@47.116.11.144 'bash -s' <<'REMOTE'
set +e
echo === ss ===
ss -ltnp | grep -E ':(7000|7013|7005|7008|8899|2053)' || true
echo === units ===
for u in wstunnel-svc-2-316.service wstunnel-svc-2-416.service wstunnel-svc-2-316-udp-uot.service leikwan-tcp-mux-7000.service; do
  echo ---$u
  systemctl is-active $u 2>/dev/null || true
  systemctl cat $u 2>/dev/null | sed -n '1,120p'
done
echo === routefile ===
for p in /var/lib/leikwan-slave-agent/listeners/routes-7000.json /var/lib/leikwan-slave-agent/routes-7000.json; do [ -e "$p" ] && { echo ---$p; sed -n '1,180p' "$p"; }; done
echo === metadata ===
sed -n '1,140p' /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json 2>/dev/null || true
echo === recent ===
journalctl -u wstunnel-svc-2-316.service -u leikwan-tcp-mux-7000.service --since '-60 min' --no-pager -o short-iso | tail -120
REMOTE
