import pathlib,re,paramiko,json
ROOT=pathlib.Path(__file__).resolve().parents[1]
env=(ROOT/'leikwan-v3.txt').read_text(encoding='utf-8')
def connect(host,via=None):
    pw=re.search(re.escape('root@'+host)+r'[^"\r\n]*"([^"]+)"',env).group(1)
    c=paramiko.SSHClient();c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    sock=via.get_transport().open_channel('direct-tcpip',(host,22),('127.0.0.1',0)) if via else None
    c.connect(host,username='root',password=pw,sock=sock,timeout=15,auth_timeout=15,look_for_keys=False,allow_agent=False)
    return c
def run(c,cmd):
    _,o,e=c.exec_command(cmd,timeout=30)
    out=o.read().decode();err=e.read().decode()
    if o.channel.recv_exit_status(): raise RuntimeError(err[:300])
    return out
if __name__=='__main__':
    linux=connect('172.21.255.189')
    print('LINUX',run(linux,"systemctl show ultra-forward-170 -p MainPID -p NRestarts; ss -lnt | head -n 20; python3 -c \"import json;d=json.load(open('/run/ultra/forward-170.status.json'));print(json.dumps({k:d.get(k) for k in ['state','error','runtime_attestation','active_tcp']}))\""))
    slave=connect('49.235.160.246');jp=connect('185.220.238.143',slave)
    print('JP',run(jp,"hostname; ss -lntup | head -n 35; command -v python3; systemctl is-active sing-box"))
    jp.close();slave.close();linux.close()
