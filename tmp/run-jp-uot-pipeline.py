import pathlib,runpy,secrets,json,time
m=runpy.run_path(str(pathlib.Path(__file__).with_name('jp-uot-recon.py')))
connect,run,ROOT=m['connect'],m['run'],m['ROOT']
local=ROOT/'tmp/jp-uot-pipeline-20260916';local.mkdir(exist_ok=True)
remote='/tmp/leikwan-jp-uot-pipeline-20260916'
linux=slave=jp=None
def upload(c,name,data):
    s=c.open_sftp()
    with s.open(remote+'/'+name,'w') as f:f.write(data)
    s.chmod(remote+'/'+name,0o600);s.close()
try:
    linux=connect('172.21.255.189');slave=connect('49.235.160.246');jp=connect('185.220.238.143',slave)
    # The first canary owns 18743; require its cleanup before continuing.
    if run(jp,"ss -lntH 'sport = :18743'").strip():
        raise RuntimeError('first lab still owns port 18743')
    for c in (linux,jp):
        run(c,'test ! -e '+remote+' && mkdir -m 700 '+remote)
    token=secrets.token_hex(32).encode()
    for c in (linux,jp):
        upload(c,'token',token)
        for name in ('japan_canary.py','pipeline_canary.py'):
            upload(c,name,(ROOT/'tools/ultra-uot-lab'/name).read_bytes())
    before=run(linux,'systemctl show ultra-forward-170 -p MainPID -p NRestarts')
    (local/'unit.before.txt').write_text(before)
    start=run(linux,'date -u +%FT%TZ').strip()
    (local/'start.txt').write_text(start)
    print(run(jp,f'systemd-run --unit=leikwan-jp-uot-pipeline-server --property=RuntimeMaxSec=450 --property=UMask=0077 /usr/bin/python3 {remote}/japan_canary.py server --token-file {remote}/token'),flush=True)
    print(run(linux,f'systemd-run --unit=leikwan-jp-uot-pipeline --property=RuntimeMaxSec=300 --property=UMask=0077 /usr/bin/python3 {remote}/pipeline_canary.py --token-file {remote}/token --duration 180 --out {remote}/result.json'),flush=True)
    for i in range(26):
        time.sleep(10)
        print(run(linux,f'cat {remote}/result.json.progress'),flush=True)
        if run(linux,f'test -f {remote}/result.json && echo done || true').strip()=='done':
            s=linux.open_sftp();s.get(remote+'/result.json',str(local/'result.json'));s.close();break
    else:raise RuntimeError('pipeline canary result unavailable')
    after=run(linux,'systemctl show ultra-forward-170 -p MainPID -p NRestarts')
    (local/'unit.after.txt').write_text(after)
    # Log only established runtime renewals, not auth/config payloads.
    journal=run(linux,f"journalctl -u leikwan-client-agent --since '{start}' --no-pager | grep -E 'Ultra lease|Ultra renewal|renewal delivered' || true")
    (local/'renewals.txt').write_text(journal)
    result=json.loads((local/'result.json').read_text())
    print('FINAL',json.dumps({'flows':result['flows'],'duration':result['ended']-result['started'],'same_unit':before==after}),flush=True)
finally:
    for c,unit in ((linux,'leikwan-jp-uot-pipeline'),(jp,'leikwan-jp-uot-pipeline-server')):
        if c:
            try:
                run(c,f'systemctl stop {unit} 2>/dev/null || true')
                run(c,f'rm -f {remote}/token')
            except Exception as e:print('CLEANUP_ERROR',unit,type(e).__name__,flush=True)
    for c in (jp,slave,linux):
        if c:c.close()
