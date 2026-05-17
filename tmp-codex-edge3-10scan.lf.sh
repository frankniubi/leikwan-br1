#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'; SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE "SECRET=$SECRET python3 -" <<'PY'
import base64,hashlib,hmac,json,os,socket,subprocess,tempfile,time,statistics,shutil
edges=['162.159.39.3','162.159.39.4','162.159.39.29','162.159.39.7','162.159.39.6']
secret=os.environ['SECRET'].encode(); path='07289d1f5ed25399'; sni='nrt1.nbwly.com'
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
def token(port=22):
 raw=json.dumps({'target_host':'127.0.0.1','target_port':port,'target_protocol':'tcp','service_id':2,'forward_id':47,'issued_at':int(time.time())-30,'expires_at':int(time.time())+1800},separators=(',',':')).encode(); return b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest())
def fp():
 s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p
def wait(p,proc,tout=2):
 end=time.time()+tout
 while time.time()<end:
  if proc.poll() is not None: return False
  try: s=socket.create_connection(('127.0.0.1',p),timeout=.1); s.close(); return True
  except Exception: time.sleep(.04)
 return False
def trial(edge):
 tmp=tempfile.mkdtemp(); wp=fp(); bp=fp();
 w=subprocess.Popen(['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{wp}:127.0.0.1:8899','--tls-sni-override',sni,'--http-headers',f'Host: {sni}','--http-upgrade-path-prefix',path,f'wss://{edge}:2053'],stdout=open(tmp+'/w.log','w'),stderr=subprocess.STDOUT,text=True)
 b=None; res={'ok':False,'ms':None,'banner':'','err':''}
 try:
  if not wait(wp,w,2): res['err']='wst_not_ready'; return res,tmp,w,b
  b=subprocess.Popen(['/usr/local/bin/leikwan-bond','client','--protocol','tcp','--listen',f'127.0.0.1:{bp}','--legs',f'tcp://127.0.0.1:{wp}?priority=10&display_host={edge}','--session-token',token(22),'--connect-timeout-ms','2500','--join-timeout-ms','800','--bulk-promote-bytes','1048576'],stdout=open(tmp+'/b.log','w'),stderr=subprocess.STDOUT,text=True)
  if not wait(bp,b,2): res['err']='bond_not_ready'; return res,tmp,w,b
  t=time.time(); s=socket.create_connection(('127.0.0.1',bp),timeout=2); s.settimeout(3); data=s.recv(80); s.close(); res.update(ok=data.startswith(b'SSH-'),ms=(time.time()-t)*1000,banner=data[:24].decode('latin1','replace'))
 except Exception as e: res['err']=type(e).__name__+':'+str(e)
 return res,tmp,w,b
for edge in edges:
 vals=[]; errs=[]; keys=[]
 for i in range(10):
  res,tmp,w,b=trial(edge)
  try:
   if res['ok']: vals.append(res['ms'])
   else: errs.append(res['err'] or res['banner'])
   wt=open(tmp+'/w.log',errors='ignore').read() if os.path.exists(tmp+'/w.log') else ''; bt=open(tmp+'/b.log',errors='ignore').read() if os.path.exists(tmp+'/b.log') else ''
   for pat in ['Invalid status code: 520','failed to do websocket handshake','failed to read bond frame header','invalid bond frame magic','no bond legs acknowledged']:
    c=wt.count(pat)+bt.count(pat)
    if c: keys.append(pat)
  finally:
   for p in [b,w]:
    if p and p.poll() is None:
     p.terminate();
     try:p.wait(timeout=.5)
     except Exception:
      p.kill(); p.wait(timeout=.5)
   shutil.rmtree(tmp,ignore_errors=True)
  time.sleep(.1)
 print('EDGE10',edge,'ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:3],'keys',dict((k,keys.count(k)) for k in set(keys)))
PY
