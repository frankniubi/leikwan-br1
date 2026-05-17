#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
sshpass -p "$CPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
cat >/tmp/codex_selfleg_no_latencyfirst.py <<'PY'
import base64,hashlib,hmac,json,pathlib,re,socket,subprocess,time,statistics
SECRET=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; ROUTE='07289d1f5ed25399'; SNI='nrt1.nbwly.com'; EDGES=['162.159.39.6','162.159.39.4','162.159.39.29']
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
def token(port):
 raw=json.dumps({"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600},separators=(',',':')).encode(); return b64(raw)+'.'+b64(hmac.new(SECRET,raw,hashlib.sha256).digest())
def free():
 s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p
def wait(p):
 for _ in range(80):
  try: s=socket.create_connection(('127.0.0.1',p),timeout=.12); s.close(); return True
  except Exception: time.sleep(.1)
 return False
def run(edge, flags):
 lp=free(); bp=free(); wlog=f'/tmp/wst-nolf-{edge}.log'; blog=f'/tmp/bond-nolf-{edge}.log'
 open(wlog,'w').close(); open(blog,'w').close()
 wp=subprocess.Popen(['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{lp}:127.0.0.1:8899','--tls-sni-override',SNI,'--http-headers',f'Host: {SNI}','--http-upgrade-path-prefix',ROUTE,f'wss://{edge}:2053'],stdout=open(wlog,'a'),stderr=subprocess.STDOUT,text=True)
 wl=wait(lp); bpok=False; vals=[]; out=''; b=None
 if wl:
  cmd=['/usr/local/bin/leikwan-bond','client','--protocol','tcp','--listen',f'127.0.0.1:{bp}','--legs',f'tcp://127.0.0.1:{lp}?priority=10&endpoint_id=0&display_host=self_leg','--session-token',token(5201)] + flags
  b=subprocess.Popen(cmd,stdout=open(blog,'a'),stderr=subprocess.STDOUT,text=True)
  bpok=wait(bp); time.sleep(1)
  if bpok:
   r=subprocess.run(['timeout','24','iperf3','-c','127.0.0.1','-p',str(bp),'-R','-b','300M','-t','10','--connect-timeout','3000'],text=True,capture_output=True)
   out=r.stdout+r.stderr
   for line in out.splitlines():
    m=re.search(r'\s([0-9.]+)\s+Mbits/sec',line)
    if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
 for p in [b,wp]:
  if p:
   p.terminate()
   try:p.wait(timeout=1)
   except: p.kill()
 text=open(wlog, errors='ignore').read()+'\n'+open(blog, errors='ignore').read()
 return {'edge':edge,'wl':wl,'bpok':bpok,'vals':vals,'max':max(vals) if vals else None,'avg':round(sum(vals)/len(vals),1) if vals else None,'has520':'Invalid status code: 520' in text,'badframe':('failed to read bond frame header' in text or 'invalid bond frame magic' in text),'est':'bond client TCP transport session established' in text,'tail':' | '.join([x.strip() for x in text.splitlines() if any(y in x for y in ['failed to connect initial','session established','cooldown','WARN','ERROR','Invalid status'])][-16:]),'out_tail':' | '.join(out.splitlines()[-8:])}
flagsets={
 'default':['--chunk-size','131072','--max-window-bytes','16777216','--retransmit-ms','300','--connect-timeout-ms','3000','--join-timeout-ms','400','--interactive-chunk-bytes','16384','--latency-guard-bytes','262144','--warmup-trigger-bytes','65536','--warmup-slice-bytes','65536','--warmup-leg-delay-ms','80','--bulk-promote-bytes','262144','--leg-cooldown-ms','1800000'],
 'big-no-lf':['--chunk-size','262144','--max-window-bytes','33554432','--retransmit-ms','1000','--connect-timeout-ms','4000','--join-timeout-ms','1000','--interactive-chunk-bytes','262144','--latency-guard-bytes','2097152','--warmup-trigger-bytes','524288','--warmup-slice-bytes','262144','--warmup-leg-delay-ms','20','--bulk-promote-bytes','1073741824','--leg-cooldown-ms','30000'],
 'small-nolf':['--chunk-size','65536','--max-window-bytes','8388608','--retransmit-ms','700','--connect-timeout-ms','3000','--join-timeout-ms','800','--interactive-chunk-bytes','8192','--latency-guard-bytes','65536','--warmup-trigger-bytes','65536','--warmup-slice-bytes','65536','--warmup-leg-delay-ms','30','--bulk-promote-bytes','262144','--leg-cooldown-ms','5000'],
}
for name,flags in flagsets.items():
 for e in EDGES:
  res=run(e,flags); res['flags']=name; print(json.dumps(res,ensure_ascii=False),flush=True); time.sleep(2)
PY
python3 /tmp/codex_selfleg_no_latencyfirst.py
REMOTE