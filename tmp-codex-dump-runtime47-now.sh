set -euo pipefail
ROOT=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
printf 'local client_agent sha: '; sha256sum "$ROOT/client-agent/client_agent.py" | awk '{print $1}'
printf 'local installer sha: '; sha256sum "$ROOT/client-agent/install_forward_runtime.py" | awk '{print $1}'
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
sshpass -e ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'python3 - <<"PY"
import json, pathlib, subprocess
for p in ["/usr/local/bin/leikwan-client-agent.py","/usr/local/bin/leikwan-client-start-47.sh","/etc/leikwan/mptcp-forward-47.json"]:
    try:
        print("SHA", p, subprocess.check_output(["sha256sum",p], text=True).strip().split()[0])
    except Exception as e: print("SHA_ERR",p,e)
paths=["/var/lib/leikwan-client-agent/applied.json","/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json","/etc/leikwan/mptcp-forward-47.json","/var/lib/leikwan-client-agent/bond-endpoint-health.json"]
for p in paths:
    print("===",p,"===")
    try:
        txt=pathlib.Path(p).read_text()
        data=json.loads(txt)
        if p.endswith("applied.json"):
            keys=["47",47]
            f=None
            for k in keys:
                if isinstance(data,dict) and k in data: f=data[k]
            print(json.dumps(f if f is not None else data, indent=2, sort_keys=True)[:14000])
        elif p.endswith("bond-endpoint-health.json"):
            # print forward 47 only if present
            out={k:v for k,v in data.items() if "47" in str(k) or (isinstance(v,dict) and str(v.get("forward_id"))=="47")}
            print(json.dumps(out or data, indent=2, sort_keys=True)[:14000])
        else:
            print(json.dumps(data, indent=2, sort_keys=True)[:14000])
    except Exception as e:
        print("ERR",e)
print("=== script47 ===")
try: print(pathlib.Path("/usr/local/bin/leikwan-client-start-47.sh").read_text()[:10000])
except Exception as e: print("ERR",e)
PY'
