set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'SECRET="1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0" python3 - <<"PY"
import base64,hashlib,hmac,json,os,socket,subprocess,tempfile,time,statistics,shutil
edges=["162.159.39.4","162.159.39.7","162.159.39.8","162.159.39.29","162.159.39.6","104.16.0.3"]
secret=os.environ["SECRET"].encode()
path="07289d1f5ed25399"; sni="nrt1.nbwly.com"
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip("=")
def make_token():
    payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
    raw=json.dumps(payload,separators=(",",":")).encode()
    return b64(raw)+"."+b64(hmac.new(secret,raw,hashlib.sha256).digest())
def freeport():
    s=socket.socket(); s.bind(("127.0.0.1",0)); p=s.getsockname()[1]; s.close(); return p
def tcp_ms(host, port):
    vals=[]
    for _ in range(3):
        t=time.time()
        try:
            s=socket.create_connection((host,port),timeout=2); s.close(); vals.append((time.time()-t)*1000)
        except Exception: pass
        time.sleep(.05)
    return round(min(vals),1) if vals else None
def trial(edge):
    wp=freeport(); bp=freeport(); tmp=tempfile.mkdtemp(prefix="lk-edge-")
    wcmd=["/usr/local/bin/wstunnel","client","-L",f"tcp://127.0.0.1:{wp}:127.0.0.1:8899","--tls-sni-override",sni,"--http-headers",f"Host: {sni}","--http-upgrade-path-prefix",path,f"wss://{edge}:2053"]
    with open(tmp+"/w.log","w") as wlog:
        w=subprocess.Popen(wcmd,stdout=wlog,stderr=wlog,text=True)
    time.sleep(.20)
    bcmd=["/usr/local/bin/leikwan-bond","client","--protocol","tcp","--listen",f"127.0.0.1:{bp}","--legs",f"tcp://127.0.0.1:{wp}?priority=10&endpoint_id=316&display_host=47.116.11.144","--session-token",make_token(),"--connect-timeout-ms","3000","--join-timeout-ms","900"]
    with open(tmp+"/b.log","w") as blog:
        b=subprocess.Popen(bcmd,stdout=blog,stderr=blog,text=True)
    t0=time.time(); ok=False; banner=b""; err=""
    try:
        deadline=time.time()+5.0
        while time.time()<deadline:
            try:
                s=socket.create_connection(("127.0.0.1",bp),timeout=.20)
                s.settimeout(2.0)
                try: banner=s.recv(80)
                except Exception: banner=b""
                s.close(); ok=banner.startswith(b"SSH-") or len(banner)>0
                break
            except Exception as e:
                err=type(e).__name__+":"+str(e)[:60]
                time.sleep(.05)
    finally:
        for p in (b,w):
            if p.poll() is None:
                p.terminate()
                try: p.wait(timeout=.8)
                except Exception:
                    p.kill(); p.wait(timeout=.8)
    elapsed=(time.time()-t0)*1000
    try:
        wt=open(tmp+"/w.log",errors="ignore").read()
        bt=open(tmp+"/b.log",errors="ignore").read()
    except Exception:
        wt=bt=""
    shutil.rmtree(tmp, ignore_errors=True)
    return {"ok":ok,"ms":round(elapsed,1),"banner":banner[:24].decode("latin1","replace"),"bad_http":wt.count("Invalid status code"),"eof":bt.count("failed to read bond frame header"),"established":bt.count("session established"),"err":err}
print("slave",socket.gethostname(),"date",time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime()))
summary=[]
for edge in edges:
    edge_tcp=tcp_ms(edge,2053)
    vals=[]; oks=0; bad=0; eofs=0; est=0; details=[]
    for _ in range(5):
        r=trial(edge); details.append(r); bad+=r["bad_http"]; eofs+=r["eof"]; est+=r["established"]
        if r["ok"]:
            oks+=1; vals.append(r["ms"])
        time.sleep(.25)
    row={"edge":edge,"edge_tcp_min_ms":edge_tcp,"ok":oks,"min_ms":round(min(vals),1) if vals else None,"avg_ms":round(statistics.mean(vals),1) if vals else None,"bad_http":bad,"eof":eofs,"established":est,"details":details}
    summary.append(row)
    print(json.dumps(row,ensure_ascii=False))
print("BEST", json.dumps(sorted(summary,key=lambda r:(-r["ok"], r["min_ms"] if r["min_ms"] is not None else 999999, r["bad_http"], r["eof"]))[:3],ensure_ascii=False))
PY'