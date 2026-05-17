#!/usr/bin/env python3
import json, os, socket, statistics, subprocess, tempfile, time
from pathlib import Path
CANDIDATES=["104.16.0.3","162.159.39.4","162.159.39.7","162.159.39.6","162.159.39.29","162.159.39.8"]
SNI="nrt1.nbwly.com"
PATH_PREFIX="07289d1f5ed25399"
SIDE="185.220.238.143"

def tcp_probe(host, port, count=5, timeout=2.0):
    vals=[]; errs=[]
    for _ in range(count):
        t=time.time()
        try:
            s=socket.create_connection((host, int(port)), timeout=timeout)
            s.close(); vals.append((time.time()-t)*1000.0)
        except Exception as e:
            errs.append(type(e).__name__+":"+str(e)[:80])
        time.sleep(0.08)
    if vals:
        return {"ok": True, "min": round(min(vals),1), "avg": round(statistics.mean(vals),1), "vals": [round(v,1) for v in vals], "errs": errs[:2]}
    return {"ok": False, "errs": errs[:3]}

def curl_probe(ip):
    cmd=["curl","-k","-sS","-o","/dev/null","-w","%{http_code} %{time_connect} %{time_appconnect} %{time_total}","--connect-timeout","2","--max-time","5","--resolve",f"{SNI}:2053:{ip}","-H",f"Host: {SNI}",f"https://{SNI}:2053/_leikwan/readyz/{PATH_PREFIX}"]
    if not Path('/usr/bin/curl').exists() and subprocess.run(['sh','-lc','command -v curl'], capture_output=True, text=True).returncode != 0:
        return {"ok": False, "err":"curl_missing"}
    vals=[]; statuses=[]; errs=[]
    for _ in range(3):
        try:
            r=subprocess.run(cmd, capture_output=True, text=True, timeout=7)
            parts=(r.stdout or '').strip().split()
            if len(parts)>=4:
                statuses.append(parts[0])
                vals.append({"connect":round(float(parts[1])*1000,1),"tls":round(float(parts[2])*1000,1),"total":round(float(parts[3])*1000,1),"status":parts[0]})
            elif r.stderr:
                errs.append(r.stderr.strip()[:160])
        except Exception as e:
            errs.append(type(e).__name__+":"+str(e)[:120])
        time.sleep(0.12)
    ok=bool(vals)
    return {"ok": ok, "vals": vals, "min_connect": min([v['connect'] for v in vals], default=None), "min_tls": min([v['tls'] for v in vals if v['tls'] > 0], default=None), "statuses": statuses, "errs": errs[:2]}

def wstunnel_probe(ip):
    w='/usr/local/bin/wstunnel'
    if not Path(w).exists():
        return {"ok": False, "err":"wstunnel_missing"}
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as ls:
        ls.bind(('127.0.0.1',0)); local=ls.getsockname()[1]
    log=Path(tempfile.mktemp(prefix='wst-probe-', suffix='.log'))
    cmd=[w,'client','-L',f'tcp://127.0.0.1:{local}:127.0.0.1:8899','--tls-sni-override',SNI,'--http-headers',f'Host: {SNI}','--http-upgrade-path-prefix',PATH_PREFIX,f'wss://{ip}:2053']
    with log.open('w+', encoding='utf-8') as fh:
        p=subprocess.Popen(cmd, stdout=fh, stderr=fh, text=True)
        t0=time.time(); connected=False; sent=False
        try:
            deadline=t0+4.0
            while time.time()<deadline:
                if p.poll() is not None: break
                try:
                    s=socket.create_connection(('127.0.0.1', local), timeout=0.15)
                    connected=True
                    try:
                        s.sendall(b'LKPROBE')
                        sent=True
                    except Exception:
                        pass
                    time.sleep(1.0)
                    try: s.close()
                    except Exception: pass
                    break
                except Exception:
                    time.sleep(0.05)
            time.sleep(0.4)
        finally:
            if p.poll() is None:
                p.terminate()
                try: p.wait(timeout=1)
                except Exception:
                    p.kill(); p.wait(timeout=1)
    text=''
    try: text=log.read_text(encoding='utf-8', errors='ignore')
    except Exception: pass
    try: log.unlink()
    except Exception: pass
    lower=text.lower()
    bad=('invalid status code' in lower) or ('failed to do websocket handshake' in lower)
    return {"ok": connected and not bad, "connected_local": connected, "sent": sent, "elapsed": round((time.time()-t0)*1000,1), "bad": bad, "tail": '\n'.join(text.splitlines()[-5:])[:500]}

out={"host": socket.gethostname(), "time": time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), "direct": {}, "cf": {}}
for port in [8899,8898,2053,5201,22]:
    out['direct'][str(port)] = tcp_probe(SIDE, port)
for ip in CANDIDATES:
    out['cf'][ip] = {"tcp2053": tcp_probe(ip,2053, count=3), "curl": curl_probe(ip), "wstunnel": wstunnel_probe(ip)}
print(json.dumps(out, ensure_ascii=False, indent=2))