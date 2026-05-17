#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'python3 - <<"PY"
import base64,json,pathlib
j=json.load(open("/etc/leikwan/mptcp-forward-47.json"))
print("runtime", {k:j.get(k) for k in ["remote_host","remote_port","relay_host","relay_port","bond_mode","direct_legs"]})
for name in ["tcp_session_token","udp_session_token"]:
 tok=j.get(name,"")
 body=tok.split(".")[0]
 body += "="*((4-len(body)%4)%4)
 print(name, json.loads(base64.urlsafe_b64decode(body)))
PY'