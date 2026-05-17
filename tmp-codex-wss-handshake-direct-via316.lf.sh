set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'python3 - <<"PY"
import socket, ssl, time
hosts=[("origin", "185.220.238.143"), ("edge4", "162.159.39.4"), ("edge29", "162.159.39.29"), ("edge8", "162.159.39.8")]
paths=["/07289d1f5ed25399", "/07289d1f5ed25399/", "/566ff12a38fd8869", "/notfound"]
for label,host in hosts:
  for path in paths:
    t=time.time(); status=""; data=b""; err=""
    try:
      ctx=ssl.create_default_context()
      ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
      raw=socket.create_connection((host,2053),timeout=4)
      s=ctx.wrap_socket(raw, server_hostname="nrt1.nbwly.com")
      s.settimeout(4)
      req=(f"GET {path} HTTP/1.1\r\nHost: nrt1.nbwly.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n").encode()
      s.sendall(req)
      try:
        data=s.recv(256)
      except Exception as e:
        err="recv="+repr(e)
      s.close()
    except Exception as e:
      err=repr(e)
    ms=(time.time()-t)*1000
    first=data.split(b"\r\n",1)[0] if data else b""
    print({"host":label,"ip":host,"path":path,"ms":round(ms,1),"first":first.decode("latin1","replace"),"len":len(data),"err":err,"hex":data[:80].hex()})
PY'