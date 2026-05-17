#!/usr/bin/env bash
set -euo pipefail
cat >/tmp/codex_selfleg_direct_scan.py <<'PY'
import base64, hashlib, hmac, json, os, pathlib, re, socket, statistics, subprocess, time
SECRET=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
ROUTE='07289d1f5ed25399'
SNI='nrt1.nbwly.com'
EDGES=['162.159.39.29','162.159.39.4','162.159.39.6','162.159.39.7','162.159.39.8','104.16.0.3']
BASE=pathlib.Path('/tmp/codex-selfleg-direct-scan'); BASE.mkdir(exist_ok=True)

def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
def token(port):
    payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
    raw=json.dumps(payload,separators=(',',':')).encode()
    return b64(raw)+'.'+b64(hmac.new(SECRET,raw,hashlib.sha256).digest())
def free_port():
    s=socket.socket(); s.bind(('127.0.0.1',0)); p=s.getsockname()[1]; s.close(); return p
def wait_port(p, n=50):
    for _ in range(n):
        try:
            s=socket.create_connection(('127.0.0.1',p),timeout=.15); s.close(); return True
        except Exception: time.sleep(.08)
    return False
def tcp_ping(host, port, samples=3):
    vals=[]; errs=[]
    for _ in range(samples):
        t=time.time()
        try:
            s=socket.create_connection((host,port),timeout=2); s.close(); vals.append(round((time.time()-t)*1000,1))
        except Exception as e: errs.append(type(e).__name__+':'+str(e))
    return vals, errs

def banner_case(edge, samples=6):
    lp=free_port(); bp=free_port(); wlog=BASE/f'wst-{edge}.log'; blog=BASE/f'bond-{edge}.log'
    for p in (wlog, blog):
        try: p.unlink()
        except FileNotFoundError: pass
    wf=open(wlog,'w'); bf=open(blog,'w')
    wp=subprocess.Popen(['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{lp}:127.0.0.1:8899','--tls-sni-override',SNI,'--http-headers',f'Host: {SNI}','--http-upgrade-path-prefix',ROUTE,f'wss://{edge}:2053'], stdout=wf, stderr=subprocess.STDOUT, text=True)
    ok_listener=wait_port(lp)
    bpok=False; vals=[]; errs=[]; established=False
    if ok_listener:
        bp_proc=subprocess.Popen(['/usr/local/bin/leikwan-bond','client','--protocol','tcp','--listen',f'127.0.0.1:{bp}','--legs',f'tcp://127.0.0.1:{lp}?priority=10&endpoint_id=0&display_host=self_leg&role=primary','--session-token',token(22),'--chunk-size','131072','--max-window-bytes','16777216','--retransmit-ms','300','--connect-timeout-ms','3000','--join-timeout-ms','400','--interactive-chunk-bytes','16384','--latency-guard-bytes','262144','--warmup-trigger-bytes','1073741824','--warmup-slice-bytes','65536','--warmup-leg-delay-ms','0','--bulk-promote-bytes','1073741824','--leg-cooldown-ms','30000','--latency-first'], stdout=bf, stderr=subprocess.STDOUT, text=True)
        bpok=wait_port(bp)
        time.sleep(.7)
        for i in range(samples):
            t=time.time(); data=b''; err=''
            try:
                s=socket.create_connection(('127.0.0.1',bp),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
            except Exception as e: err=type(e).__name__+':'+str(e)
            ms=round((time.time()-t)*1000,1)
            if data.startswith(b'SSH-'): vals.append(ms)
            else: errs.append((ms,err,data[:24].hex()))
            time.sleep(.15)
    else:
        bp_proc=None
    time.sleep(.5)
    for p in [bp_proc, wp]:
        if p:
            try: p.terminate()
            except Exception: pass
    for p in [bp_proc, wp]:
        if p:
            try: p.wait(timeout=1)
            except Exception:
                try: p.kill()
                except Exception: pass
    wf.close(); bf.close()
    text=(wlog.read_text(errors='ignore') if wlog.exists() else '')+'\n'+(blog.read_text(errors='ignore') if blog.exists() else '')
    established='bond client TCP transport session established' in text
    result={'edge':edge,'edge_tcp_ms':tcp_ping(edge,2053)[0],'listener':ok_listener,'bond_listener':bpok,'ok':len(vals),'fail':len(errs),'min_ms':min(vals) if vals else None,'avg_ms':round(statistics.mean(vals),1) if vals else None,'vals':vals,'errs':errs[:3],'established':established,'has_520':'Invalid status code: 520' in text,'invalid_magic':'invalid bond frame magic' in text,'early_eof':'early eof' in text,'log_tail':' | '.join([ln.strip() for ln in text.splitlines() if any(x in ln for x in ['Invalid status','websocket handshake','failed to connect initial','established','invalid bond','early eof','WARN','ERROR'])][-12:])}
    print(json.dumps(result,ensure_ascii=False), flush=True)
    return result

results=[banner_case(e) for e in EDGES]
best=sorted([r for r in results if r['ok']>0], key=lambda r:(r['min_ms'], r['avg_ms'] or 9999))[:3]
print('BEST '+json.dumps(best,ensure_ascii=False), flush=True)
PY
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
python3 /tmp/codex_selfleg_direct_scan.py