#!/usr/bin/env bash
set -euo pipefail
cat >/tmp/codex_selfleg_iperf_test.py <<'PY'
import base64, hashlib, hmac, json, os, pathlib, re, socket, statistics, subprocess, time
SECRET=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; ROUTE='07289d1f5ed25399'; SNI='nrt1.nbwly.com'
EDGES=['162.159.39.6','162.159.39.29','162.159.39.4']
BASE=pathlib.Path('/tmp/codex-selfleg-iperf-now'); BASE.mkdir(exist_ok=True)
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
def token(port):
 payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}; raw=json.dumps(payload,separators=(',',':')).encode(); return b64(raw)+'.'+b64(hmac.new(SECRET,raw,hashlib.sha256).digest())
def free_port():
 s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p
def wait_port(p):
 for _ in range(60):
  try: s=socket.create_connection(('127.0.0.1',p),timeout=.15); s.close(); return True
  except Exception: time.sleep(.1)
 return False
def run(edge,target=5201):
 lp=free_port(); bp=free_port(); wlog=BASE/f'wst-{edge}-{target}.log'; blog=BASE/f'bond-{edge}-{target}.log'; out=BASE/f'iperf-{edge}-{target}.out'
 for f in [wlog,blog,out]:
  try: f.unlink()
  except FileNotFoundError: pass
 wf=open(wlog,'w'); bf=open(blog,'w')
 wp=subprocess.Popen(['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{lp}:127.0.0.1:8899','--tls-sni-override',SNI,'--http-headers',f'Host: {SNI}','--http-upgrade-path-prefix',ROUTE,f'wss://{edge}:2053'], stdout=wf, stderr=subprocess.STDOUT, text=True)
 wl=wait_port(lp)
 bp_proc=None; bl=False; rc=None; stdout=''
 if wl:
  bp_proc=subprocess.Popen(['/usr/local/bin/leikwan-bond','client','--protocol','tcp','--listen',f'127.0.0.1:{bp}','--legs',f'tcp://127.0.0.1:{lp}?priority=10&endpoint_id=0&display_host=self_leg&role=primary','--session-token',token(target),'--chunk-size','131072','--max-window-bytes','16777216','--retransmit-ms','300','--connect-timeout-ms','3000','--join-timeout-ms','400','--interactive-chunk-bytes','16384','--latency-guard-bytes','262144','--warmup-trigger-bytes','1073741824','--warmup-slice-bytes','65536','--warmup-leg-delay-ms','0','--bulk-promote-bytes','1073741824','--leg-cooldown-ms','30000','--latency-first'], stdout=bf, stderr=subprocess.STDOUT, text=True)
  bl=wait_port(bp); time.sleep(1)
  if bl:
   r=subprocess.run(['timeout','22','iperf3','-c','127.0.0.1','-p',str(bp),'-R','-b','300M','-t','8','--connect-timeout','3000'], text=True, capture_output=True)
   rc=r.returncode; stdout=r.stdout+r.stderr; out.write_text(stdout)
 for p in [bp_proc,wp]:
  if p:
   try: p.terminate()
   except Exception: pass
 for p in [bp_proc,wp]:
  if p:
   try: p.wait(timeout=1)
   except Exception:
    try: p.kill()
    except Exception: pass
 wf.close(); bf.close()
 text=(wlog.read_text(errors='ignore') if wlog.exists() else '')+'\n'+(blog.read_text(errors='ignore') if blog.exists() else '')
 vals=[]
 for line in stdout.splitlines():
  m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)
  if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
 print(json.dumps({'edge':edge,'target':target,'wst_listener':wl,'bond_listener':bl,'iperf_rc':rc,'intervals':vals,'max':max(vals) if vals else None,'avg':round(sum(vals)/len(vals),2) if vals else None,'tail':' | '.join(stdout.splitlines()[-8:]),'established':'bond client TCP transport session established' in text,'has_520':'Invalid status code: 520' in text,'early_eof':'early eof' in text,'resets':text.count('recv_reset')+text.count('local_read_failed'),'log_tail':' | '.join([ln.strip() for ln in text.splitlines() if any(x in ln for x in ['Invalid status','websocket handshake','failed to connect initial','established','early eof','recv_reset','local_read_failed','WARN','ERROR'])][-16:])}, ensure_ascii=False), flush=True)
for e in EDGES: run(e,5201)
PY
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
python3 /tmp/codex_selfleg_iperf_test.py