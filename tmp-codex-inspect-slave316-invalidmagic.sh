set -euo pipefail
SLAVE_HOST='47.116.11.144'
SLAVE_PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$SLAVE_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE_HOST 'bash -s' <<'RS'
set +e
echo "=== slave host ==="; hostname; date -Is
echo "=== services interesting ==="
systemctl list-units --all --no-pager | grep -Ei 'leikwan|wstunnel|ingress|bond|566ff12a|07289d' | sed -n '1,160p'
echo "=== listeners ==="
ss -ltnp | grep -E ':(7000|8899|8898|2053|2[0-9]{4}|3[0-9]{4})' | sed -n '1,200p'
echo "=== processes ==="
ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan|wstunnel|ingress|bond' | grep -v grep | sed -n '1,240p'
echo "=== grep route keys in configs ==="
grep -R "566ff12a38fd8869\|07289d1f5ed25399\|185.220.238.143\|8899\|2053\|nrt1.nbwly.com" -n /etc/systemd/system /etc/leikwan* /var/lib/leikwan* /usr/local/bin 2>/dev/null | sed -n '1,260p'
echo "=== recent logs key/magic ==="
journalctl --since '-15 min' --no-pager | grep -E '566ff12a|07289d|invalid bond|bond frame|leikwan-ingress|wstunnel|cf-forward|route' | tail -220 || true
RS
