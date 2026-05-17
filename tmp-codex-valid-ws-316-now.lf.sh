#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE 'python3 -' <<'PY'
import socket,ssl,time,base64,os
ips=['185.220.238.143','162.159.39.4','162.159.39.6','162.159.39.7','162.159.39.8','162.159.39.29','104.16.0.3']
for ip in ips:
    for path in ['/07289d1f5ed25399','/07289d1f5ed25399/']:
        t=time.time(); data=b''; err=''
        try:
            ctx=ssl.create_default_context(); ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
            raw=socket.create_connection((ip,2053),timeout=4)
            s=ctx.wrap_socket(raw, server_hostname='nrt1.nbwly.com'); s.settimeout(4)
            key=base64.b64encode(os.urandom(16)).decode()
            req=(f'GET {path} HTTP/1.1\r\nHost: nrt1.nbwly.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode()
            s.sendall(req); data=s.recv(512); s.close()
        except Exception as e: err=repr(e)
        print({'ip':ip,'path':path,'ms':round((time.time()-t)*1000,1),'first':(data.split(b'\r\n',1)[0] if data else b'').decode('latin1','replace'),'len':len(data),'err':err})
PY
