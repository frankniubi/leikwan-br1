set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set +e
echo '=== client binaries ==='
/usr/local/bin/leikwan-bond --version 2>/dev/null || true
sha256sum /usr/local/bin/leikwan-bond /usr/local/bin/leikwan-ingress /usr/local/bin/wstunnel 2>/dev/null
ls -l /usr/local/bin/leikwan-bond /usr/local/bin/leikwan-ingress /usr/local/bin/wstunnel 2>/dev/null
strings /usr/local/bin/leikwan-bond | grep -E 'invalid bond frame magic|leg-cooldown|LBND|bond client TCP transport session established' | head -20 || true
echo '=== onegood script ==='
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null
echo '=== service ==='
systemctl cat leikwan-client-47-onegood.service 2>/dev/null
REMOTE