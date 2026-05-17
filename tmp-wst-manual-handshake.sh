set +e
echo '--- direct via WST to 127.0.0.1:8899 then target 22 ---'
pkill -f 'wstunnel.*127.0.0.1:24022' 2>/dev/null || true
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:24022:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix 07289d1f5ed25399 wss://162.159.39.29:2053 >/tmp/w24022.log 2>&1 & echo $! >/tmp/w24022.pid
for i in $(seq 1 30); do timeout .2 bash -lc '</dev/tcp/127.0.0.1/24022' >/dev/null 2>&1 && break || sleep .1; done
python3 - <<'PY'
import base64,hashlib,hmac,json,socket,struct,time,os
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
tok=b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest())
hs=json.dumps({"protocol":"tcp","session_token":tok,"forward_id":47}).encode()
s=socket.create_connection(('127.0.0.1',24022),timeout=3); s.settimeout(4)
s.sendall(struct.pack('>I',len(hs))+hs)
t=time.time()
try: data=s.recv(120)
except Exception as e: data=(type(e).__name__+':'+str(e)).encode()
print('ms',round((time.time()-t)*1000,1),'data',data[:80])
s.close()
PY
echo '--- direct via WST to 127.0.0.1:8899 then target 5201 iperf? manual handshake impossible for iperf direct ---'
echo '--- wst log ---'; cat /tmp/w24022.log | tail -80
kill $(cat /tmp/w24022.pid) 2>/dev/null || true