set -euo pipefail
HOST=47.116.11.144
PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@$HOST 'python3 - <<"PY"
import json,pathlib
for p in ["/var/lib/leikwan-slave/listeners/routes-7000.json","/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json","/var/lib/leikwan-slave/services/wstunnel-svc-2-316-udp-uot.json"]:
 print("===",p,"===")
 try: print(json.dumps(json.loads(pathlib.Path(p).read_text()), indent=2)[:9000])
 except Exception as e: print(e)
PY
systemctl cat leikwan-tcp-mux-7000.service wstunnel-svc-2-316.service wstunnel-svc-2-316-udp-uot.service --no-pager
journalctl -u leikwan-tcp-mux-7000.service -n 80 --no-pager || true
'
