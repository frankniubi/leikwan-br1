#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'
ART='artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64'
VER='artifacts/versions.json'
cd "$WORKDIR"
echo '=== local musl artifact ==='
sha256sum "$ART" "$VER"
sshpass -p "$PASS_PANEL" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "$ART" "$VER" root@10.0.10.196:/tmp/ >/dev/null
sshpass -p "$PASS_PANEL" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 "set -e; install -m 0755 /tmp/leikwan-mptcp-relay_0.1.0_linux_amd64 $PANEL_ROOT/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64; install -m 0644 /tmp/versions.json $PANEL_ROOT/artifacts/versions.json; echo ===panel===; sha256sum $PANEL_ROOT/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64 $PANEL_ROOT/artifacts/versions.json"
sshpass -p "$PASS_CLIENT" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "$ART" root@114.80.33.50:/tmp/leikwan-mptcp-relay.musl >/dev/null
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set -e
backup_dir=/root/codex-backups/mptcp-relay-musl-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$backup_dir"
cp -a /var/lib/leikwan-client-agent/leikwan-mptcp-relay "$backup_dir/leikwan-mptcp-relay.before"
install -m 0755 /tmp/leikwan-mptcp-relay.musl /var/lib/leikwan-client-agent/leikwan-mptcp-relay
echo ===client-new===
sha256sum /var/lib/leikwan-client-agent/leikwan-mptcp-relay
/var/lib/leikwan-client-agent/leikwan-mptcp-relay --help >/tmp/relay-help.out
head -5 /tmp/relay-help.out
systemctl restart leikwan-client-47.service
sleep 5
systemctl is-active leikwan-client-47.service
ss -ltnp | grep -E ":(11035|39559)" || true
echo backup_dir=$backup_dir
'