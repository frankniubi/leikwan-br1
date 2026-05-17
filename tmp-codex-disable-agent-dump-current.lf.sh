#!/usr/bin/env bash
set -euo pipefail
: "${SSHPASS:?SSHPASS missing}"
ssh_common=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -e ssh "${ssh_common[@]}" root@114.80.33.50 'set -e
 echo ==time==; date -Is
 echo ==disable-agent==
 systemctl disable --now leikwan-client-agent.service || true
 systemctl reset-failed leikwan-client-agent.service || true
 echo enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)
 echo active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)
 echo ==f47-services==
 echo f47_active=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true)
 systemctl status leikwan-client-47.service --no-pager -l 2>/dev/null | sed -n "1,24p" || true
 echo ==runtime-json==
 python3 - <<"PY"
import json, os
paths=["/etc/leikwan/mptcp-forward-47.json","/usr/local/bin/leikwan-client-start-47.sh","/var/lib/leikwan-client-agent/self-leg-best-ip-cache.json","/var/lib/leikwan-client-agent/forward-47.json"]
for p in paths:
    print("---",p)
    if not os.path.exists(p): print("missing"); continue
    if p.endswith(".json"):
        try:
            d=json.load(open(p))
            if isinstance(d,dict):
                for k in ["bond_mode","remote_host","remote_port","relay_host","relay_port","direct_legs","listen_port","target_port","forward_id"]:
                    if k in d: print(k,"=",d[k])
                if "47" in d: print("47 =", d["47"])
                if "forwards" in d: print("forwards_len=", len(d.get("forwards") or []))
                other=[k for k in d.keys() if k not in {"bond_mode","remote_host","remote_port","relay_host","relay_port","direct_legs","listen_port","target_port","forward_id","47","forwards"}]
                if other: print("keys=", other[:20])
            else: print(type(d), d)
        except Exception as e:
            print("jsonerr",e); print(open(p,errors="replace").read()[:1000])
    else:
        for line in open(p,errors="replace"):
            if any(x in line for x in ["wstunnel","leikwan-mptcp-relay","127.0.0.1","2053","889"]): print(line.rstrip())
PY
 echo ==ports==
 ss -ltnp | grep -E ":(11035|39559|8898|8899|2053)" || true
 echo ==recent-log==
 journalctl -u leikwan-client-47.service -n 80 --no-pager -o short-iso | tail -80 || true'