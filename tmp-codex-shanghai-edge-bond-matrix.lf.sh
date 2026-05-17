#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
while IFS='|' read -r host region user pass rest; do
  [ -n "$host" ] || continue
  echo "===== MATRIX $host ====="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" "SECRET=$SECRET python3 -" <<'PY' || true
import base64,hashlib,hmac,json,os,socket,subprocess,tempfile,time,statistics,shutil
edges=['162.159.39.4','162.159.39.6','162.159.39.7','162.159.39.8','162.159.39.29','104.16.0.3']
secret=os.environ['SECRET'].encode(); path='07289d1f5ed25399'; sni='nrt1.nbwly.com'
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
def make_token(target_port=22):
    payload={'target_host':'127.0.0.1','target_port':target_port,'target_protocol':'tcp','service_id':2,'forward_id':47,'issued_at':int(time.time())-30,'expires_at':int(time.time())+1800}
    raw=json.dumps(payload,separators=(',',':')).encode()
    return b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest())
def freeport():
    s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p
def tcp_min(host,port,n=3):
    vals=[]; errs=[]
    for _ in range(n):
        t=time.time()
        try:
            s=socket.create_connection((host,port),timeout=2); s.close(); vals.append((time.time()-t)*1000)
        except Exception as e: errs.append(type(e).__name__+':'+str(e)[:40])
        time.sleep(.05)
    return (round(min(vals),1) if vals else None, [round(v,1) for v in vals], errs[:2])
def wait_port(port, proc, timeout=2.0):
    end=time.time()+timeout
    while time.time()<end:
        if proc.poll() is not None: return False
        try:
            s=socket.create_connection(('127.0.0.1',port),timeout=.08); s.close(); return True
        except Exception: time.sleep(.04)
    return False
def trial(edge):
    wp=freeport(); bp=freeport(); tmp=tempfile.mkdtemp(prefix='lk-edge-matrix-')
    wcmd=['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{wp}:127.0.0.1:8899','--tls-sni-override',sni,'--http-headers',f'Host: {sni}','--http-upgrade-path-prefix',path,f'wss://{edge}:2053']
    with open(tmp+'/w.log','w') as wlog:
        w=subprocess.Popen(wcmd,stdout=wlog,stderr=wlog,text=True)
    ready=wait_port(wp,w,2.0)
    b=None; banner=b''; err=''; elapsed=None; conn_ok=False
    try:
        if ready:
            bcmd=['/usr/local/bin/leikwan-bond','client','--protocol','tcp','--listen',f'127.0.0.1:{bp}','--legs',f'tcp://127.0.0.1:{wp}?priority=10&display_host=self_leg','--session-token',make_token(22),'--connect-timeout-ms','2500','--join-timeout-ms','800','--bulk-promote-bytes','1048576']
            with open(tmp+'/b.log','w') as blog:
                b=subprocess.Popen(bcmd,stdout=blog,stderr=blog,text=True)
            if wait_port(bp,b,1.5):
                t=time.time()
                try:
                    s=socket.create_connection(('127.0.0.1',bp),timeout=1.0); s.settimeout(2.2)
                    conn_ok=True
                    try: banner=s.recv(96)
                    except Exception as e: err=type(e).__name__+':'+str(e)[:60]
                    s.close(); elapsed=(time.time()-t)*1000
                except Exception as e: err=type(e).__name__+':'+str(e)[:60]
            else:
                err='bond_listener_not_ready'
        else:
            err='wst_listener_not_ready'
    finally:
        for p in [b,w]:
            if p is not None and p.poll() is None:
                p.terminate()
                try: p.wait(timeout=.7)
                except Exception:
                    try: p.kill(); p.wait(timeout=.7)
                    except Exception: pass
    wt=open(tmp+'/w.log',errors='ignore').read() if os.path.exists(tmp+'/w.log') else ''
    bt=open(tmp+'/b.log',errors='ignore').read() if os.path.exists(tmp+'/b.log') else ''
    shutil.rmtree(tmp, ignore_errors=True)
    ok=banner.startswith(b'SSH-')
    return {'ok':ok,'conn_ok':conn_ok,'ms':round(elapsed,1) if elapsed is not None else None,'banner':banner[:32].decode('latin1','replace'),'err':err,'bad_http':wt.count('Invalid status code'),'ws_fail':wt.count('failed to do websocket handshake'),'eof':bt.count('failed to read bond frame header'),'invalid_magic':bt.count('invalid bond frame magic'),'no_legs':bt.count('no bond legs'),'established':bt.count('session established')+bt.count('transport session established')}
print(json.dumps({'host':socket.gethostname(),'time':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}), flush=True)
rows=[]
for edge in edges:
    tcp, tcpvals, tcperrs = tcp_min(edge,2053)
    details=[]; vals=[]; ok=0; bad=0; ws=0; eof=0; inv=0; est=0
    for _ in range(3):
        r=trial(edge); details.append(r); bad+=r['bad_http']; ws+=r['ws_fail']; eof+=r['eof']; inv+=r['invalid_magic']; est+=r['established']
        if r['ok']:
            ok+=1; vals.append(r['ms'])
        time.sleep(.2)
    row={'edge':edge,'edge_tcp_min_ms':tcp,'edge_tcp_vals':tcpvals,'ok':ok,'min_ms':round(min(vals),1) if vals else None,'avg_ms':round(statistics.mean(vals),1) if vals else None,'bad_http':bad,'ws_fail':ws,'eof':eof,'invalid_magic':inv,'established':est,'details':details}
    rows.append(row); print(json.dumps(row,ensure_ascii=False),flush=True)
print('BEST '+json.dumps(sorted(rows,key=lambda r:(-r['ok'], r['min_ms'] if r['min_ms'] is not None else 999999, r['bad_http'], r['ws_fail'], r['eof']))[:3],ensure_ascii=False),flush=True)
PY
  echo
done < shanghais.txt
