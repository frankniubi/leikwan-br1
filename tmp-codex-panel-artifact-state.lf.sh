#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && python3 - <<"PY"
import json,pathlib,hashlib,re
v=json.loads(pathlib.Path("artifacts/versions.json").read_text())
for k in ["sidecar_agent","bond_sidecar_installer","mptcp_sidecar_runtime","leikwan_bond_linux_amd64"]:
    print(k, v.get(k))
for p in ["sidecar-agent/sidecar_agent.py","install/sidecar-agent-update.sh"]:
    path=pathlib.Path(p)
    print("---",p, path.exists(), hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else "")
    if path.exists() and p.endswith(".py"):
        text=path.read_text()
        print(re.search(r"AGENT_VERSION\s*=\s*\"([^\"]+)\"",text).group(1), "managed_iperf3" in text, "config_patch" in text)
PY'
