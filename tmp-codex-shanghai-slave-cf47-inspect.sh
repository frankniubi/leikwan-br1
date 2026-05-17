set -euo pipefail
ROOT=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
mapfile -t SLAVES < <(awk -F'|' 'NF>=4 {print $1"|"$3"|"$4}' "$ROOT/shanghais.txt")
for s in "${SLAVES[@]}"; do
  IFS='|' read -r shost suser spass <<<"$s"
  echo "===== SLAVE $shost ====="
  sshpass -p "$spass" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 "$suser@$shost" 'set +e
hostname; date -Is
echo === 7000 listeners ===
ss -ltnp | grep -E ":(7000|2053|8899|8898)" || true
echo === services matching 47/316/sidecar/ingress/wstunnel ===
systemctl list-units --type=service --all --no-legend | grep -E "(316|317|318|319|320|wstunnel|ingress|slave-agent|sidecar)" | head -80 || true
echo === active service snippets ===
for u in $(systemctl list-units --type=service --all --no-legend | awk "/(316|wstunnel-svc-2|ingress|slave-agent)/{print \$1}" | head -20); do echo ---$u; systemctl status "$u" --no-pager -l | sed -n "1,35p"; done
echo === files 7000 ===
grep -R "07289d1f5ed25399\|185.220.238.143\|8899\|nrt1.nbwly.com\|:7000" -n /etc/systemd/system /var/lib/leikwan* /usr/local/bin 2>/dev/null | head -120 || true
' || echo ssh_failed
 done
