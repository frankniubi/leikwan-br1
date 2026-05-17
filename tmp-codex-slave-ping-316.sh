set -euo pipefail
HOST=47.116.11.144
PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@$HOST 'set +e; printf "PING correct auth -> "; python3 - <<"PY"
import socket
host="127.0.0.1"; port=7000; rk="07289d1f5ed25399"; token="2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e"
s=socket.create_connection((host,port), timeout=3); s.settimeout(3); s.sendall(f"LEIKWAN-INGRESS 1 PING {rk} {token}\n".encode()); print(repr(s.recv(100))); s.close()
PY
printf "connect raw route -> "; timeout 3 bash -lc "</dev/tcp/127.0.0.1/7002" && echo 7002open || echo fail
'
