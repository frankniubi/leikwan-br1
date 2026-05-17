#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
cat >/tmp/codex_selfleg_target_matrix.py <<'PY'
import base64,hashlib,hmac,json,pathlib,re,socket,statistics,subprocess,time,os,signal
SECRET=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; ROUTE='07289d1f5ed25399'; SNI='nrt1.nbwly.com'; EDGE='162.159.39.6'
BASE=pathlib.Path('/tmp/codex-target-matrix-now'); BASE.mkdir(exist_ok=True)
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
def token(port):
 payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}; raw=json.dumps(payload,separators=(',',':')).encode(); return b64(raw)+'.'+b64(hmac.new(SECRET,raw,hashlib.sha256).digest())
def free_port():
 s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p
def wait(p,n=60):
 for _ in range(n):
  try: s=socket.create_connection(('127.0.0.1',p),timeout=.12); s.close(); return True
  except Exception: time.sleep(.1)
 return False
def run(wst_target, token_target, mode):
 lp=free_port(); bp=free_port(); wlog=BASE/f'wst-{wst_target}-{token_target}-{mode}.log'; blog=BASE/f'bond-{wst_target}-{token_target}-{mode}.log'; out=BASE/f'out-{wst_target}-{token_target}-{mode}.txt'
 for f in [wlog,blog,out]:
  try:f.unlink()
  except:pass
 wf=open(wlog,'w'); bf=open(blog,'w')
 wp=subprocess.Popen(['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{lp}:127.0.0.1:{wst_target}','--tls-sni-override',SNI,'--http-headers',f'Host: {SNI}','--http-upgrade-path-prefix',ROUTE,f'wss://{EDGE}:2053'],stdout=wf,stderr=subprocess.STDOUT,text=True)
 wl=wait(lp)
 bpok=False; vals=[]; err=[]; stdout=''; bp_proc=None
 if wl:
  bp_proc=subprocess.Popen(['/usr/local/bin/leikwan-bond','client','--protocol','tcp','--listen',f'127.0.0.1:{bp}','--legs',f'tcp://127.0.0.1:{lp}?priority=10&endpoint_id=0&display_host=self_leg','--session-token',token(token_target),'--chunk-size','131072','--max-window-bytes','16777216','--retransmit-ms','300','--connect-timeout-ms','3000','--join-timeout-ms','400','--interactive-chunk-bytes','16384','--latency-guard-bytes','262144','--warmup-trigger-bytes','1073741824','--warmup-slice-bytes','65536','--warmup-leg-delay-ms','0','--bulk-promote-bytes','1073741824','--leg-cooldown-ms','30000','--latency-first'],stdout=bf,stderr=subprocess.STDOUT,text=True)
  bpok=wait(bp); time.sleep(.7)
  if bpok and mode=='banner':
   for i in range(5):
    t=time.time(); data=b''; e=''
    try:
     s=socket.create_connection(('127.0.0.1',bp),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
    except Exception as ex:e=type(ex).__name__+':'+str(ex)
    ms=(time.time()-t)*1000
    if data.startswith(b'SSH-'): vals.append(ms)
    else: err.append((round(ms,1),e,data[:16].hex()))
    time.sleep(.15)
  if bpok and mode=='iperf':
   r=subprocess.run(['timeout','18','iperf3','-c','127.0.0.1','-p',str(bp),'-R','-b','300M','-t','6','--connect-timeout','3000'],text=True,capture_output=True)
   stdout=r.stdout+r.stderr; out.write_text(stdout)
   for line in stdout.splitlines():
    m=re.search(r'\s([0-9.]+)\s+Mbits/sec',line)
    if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
 for p in [bp_proc,wp]:
  if p:
   p.terminate()
   try:p.wait(timeout=1)
   except: p.kill()
 wf.close(); bf.close()
 text=(wlog.read_text(errors='ignore') if wlog.exists() else '')+'\n'+(blog.read_text(errors='ignore') if blog.exists() else '')
 print(json.dumps({'wst_target':wst_target,'token_target':token_target,'mode':mode,'wst_listener':wl,'bond_listener':bpok,'vals':[round(x,1) for x in vals],'ok':len(vals) if mode=='banner' else bool(vals),'min':round(min(vals),1) if vals else None,'avg':round(sum(vals)/len(vals),1) if vals else None,'errs':err[:3],'has400':'Invalid status code: 400' in text,'has520':'Invalid status code: 520' in text,'established':'bond client TCP transport session established' in text,'badframe':('invalid bond frame magic' in text or 'failed to read bond frame header' in text),'tail':' | '.join([ln.strip() for ln in text.splitlines() if any(x in ln for x in ['Invalid status','failed to do websocket','failed to connect initial','session established','early eof','invalid bond','failed to read bond','WARN','ERROR'])][-10:]),'iperf_tail':' | '.join(stdout.splitlines()[-8:])},ensure_ascii=False),flush=True)
for wt in [8899,8898]:
 for tt in [22,5201,5202]:
  run(wt,tt,'banner' if tt==22 else 'iperf')
PY
python3 /tmp/codex_selfleg_target_matrix.py
REMOTE