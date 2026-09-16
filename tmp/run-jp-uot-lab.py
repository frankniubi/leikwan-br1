import pathlib,runpy,secrets,json,time,sys
m=runpy.run_path(str(pathlib.Path(__file__).with_name('jp-uot-recon.py')))
connect,run=m['connect'],m['run']
ROOT=m['ROOT']; local=ROOT/'tmp'/'jp-uot-canary-20260916';local.mkdir(exist_ok=True)
remote='/tmp/leikwan-jp-uot-lab-20260916'
linux=slave=jp=None
def upload(c,name,data,mode=0o600):
    s=c.open_sftp()
    with s.open(remote+'/'+name,'w') as f:f.write(data)
    s.chmod(remote+'/'+name,mode);s.close()
def fetch(c,name):
    s=c.open_sftp();s.get(remote+'/'+name,str(local/name));s.close()
try:
    linux=connect('172.21.255.189');slave=connect('49.235.160.246');jp=connect('185.220.238.143',slave)
    # Refuse collision with a previous run; do not overwrite existing services.
    for c in (linux,jp):
        run(c,'test ! -e '+remote+' && mkdir -m 700 '+remote)
    token=secrets.token_hex(32).encode()
    for c in (linux,jp):
        upload(c,'token',token)
        upload(c,'lab.py',(ROOT/'tools/ultra-uot-lab/japan_canary.py').read_bytes())
    before=run(linux,'systemctl show ultra-forward-170 -p MainPID -p NRestarts')
    (local/'unit.before.txt').write_text(before)
    print(run(jp,f'systemd-run --unit=leikwan-jp-uot-lab --property=RuntimeMaxSec=1200 --property=UMask=0077 /usr/bin/python3 {remote}/lab.py server --token-file {remote}/token'),flush=True)
    print('SMOKE',run(linux,f'python3 {remote}/lab.py canary --token-file {remote}/token --duration 10 --out {remote}/smoke.json'),flush=True)
    fetch(linux,'smoke.json')
    smoke=json.loads((local/'smoke.json').read_text())
    if any(f['received']==0 or f['corrupt'] for f in smoke['flows']):
        raise RuntimeError('Smoke did not carry intact UDP; soak not started')
    print(run(linux,f'systemd-run --unit=leikwan-jp-uot-canary --property=RuntimeMaxSec=700 --property=UMask=0077 /usr/bin/python3 {remote}/lab.py canary --token-file {remote}/token --duration 600 --out {remote}/result.json'),flush=True)
    for i in range(65):
        time.sleep(10)
        print(run(linux,f'cat {remote}/result.json.progress'),flush=True)
        if run(linux,f'test -f {remote}/result.json && echo done || true').strip()=='done':
            fetch(linux,'result.json');break
    else: raise RuntimeError('canary result unavailable after 650 seconds')
    after=run(linux,'systemctl show ultra-forward-170 -p MainPID -p NRestarts')
    (local/'unit.after.txt').write_text(after)
    result=json.loads((local/'result.json').read_text())
    print('FINAL',json.dumps({'flows':result['flows'],'duration':result['ended']-result['started'],'same_unit':before==after}),flush=True)
finally:
    for c,unit in ((linux,'leikwan-jp-uot-canary'),(jp,'leikwan-jp-uot-lab')):
        if c:
            try:
                run(c,f'systemctl stop {unit} 2>/dev/null || true')
                run(c,f'rm -f {remote}/token')
            except Exception as e:print('CLEANUP_ERROR',unit,type(e).__name__,flush=True)
    for c in (jp,slave,linux):
        if c:c.close()
