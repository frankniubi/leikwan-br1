#!/usr/bin/env bash
set -euo pipefail
PASS='Frank20021212.'
cat > /tmp/selfleg_iperf_edge_matrix.py <<'PY'
import json, pathlib, subprocess, socket, time, os, re
cfg=json.loads(pathlib.Path('/etc/leikwan/mptcp-forward-47.json').read_text())
ips=['104.16.0.3','162.159.39.4','162.159.39.7','162.159.39.6','162.159.39.29','162.159.39.8']
sni='nrt1.nbwly.com'; path='07289d1f5ed25399'; target_port=8899
for idx,ip in enumerate(ips):
    lp=43000+idx
    log=f'/tmp/selfleg-iperf-{idx}.log'
    try: os.unlink(log)
    except FileNotFoundError: pass
    cmd=['/usr/local/bin/wstunnel','client','-L',f'tcp://127.0.0.1:{lp}:127.0.0.1:{target_port}','--tls-sni-override',sni,'--http-headers',f'Host: {sni}','--http-upgrade-path-prefix',path,f'wss://{ip}:2053']
    f=open(log,'w')
    p=subprocess.Popen(cmd, stdout=f, stderr=subprocess.STDOUT, text=True)
    # wait local listener
    ready=False
    for _ in range(40):
        if p.poll() is not None: break
        try:
            s=socket.create_connection(('127.0.0.1',lp),timeout=0.1); s.close(); ready=True; break
        except Exception: time.sleep(0.05)
    result={'ip':ip,'local_port':lp,'listener_ready':ready}
    if ready:
        t=time.time()
        r=subprocess.run(['timeout','15','iperf3','-c','127.0.0.1','-p',str(lp),'-R','-b','300M','-t','4','--json'],text=True,capture_output=True)
        result['iperf_rc']=r.returncode
        result['elapsed_ms']=round((time.time()-t)*1000,1)
        try:
            j=json.loads(r.stdout)
            result['iperf_error']=j.get('error','')
            result['bps']=j.get('end',{}).get('sum_received',{}).get('bits_per_second') or j.get('end',{}).get('sum_sent',{}).get('bits_per_second')
        except Exception:
            result['iperf_error']='parse_failed:'+(r.stdout+r.stderr)[-200:]
    else:
        result['iperf_error']='local_listener_not_ready'
    time.sleep(0.5)
    try: p.terminate()
    except Exception: pass
    try: p.wait(timeout=1)
    except Exception:
        try: p.kill()
        except Exception: pass
    f.close()
    text=pathlib.Path(log).read_text(errors='ignore') if pathlib.Path(log).exists() else ''
    result['has_520']='Invalid status code: 520' in text
    result['has_ws_fail']='failed to do websocket handshake' in text
    result['log_tail']=' | '.join([ln.strip() for ln in text.splitlines() if ('ERROR' in ln or 'Invalid status' in ln or 'Opening TCP' in ln or 'TLS handshake' in ln or 'Closing' in ln)][-12:])
    print(json.dumps(result,ensure_ascii=False), flush=True)
PY
sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/selfleg_iperf_edge_matrix.py root@114.80.33.50:/tmp/selfleg_iperf_edge_matrix.py >/dev/null
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'python3 /tmp/selfleg_iperf_edge_matrix.py'
