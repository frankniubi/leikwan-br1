#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'python3 - <<"PY"
import json, pathlib, urllib.request
cfg=json.loads(pathlib.Path("/etc/leikwan-client-agent/config.json").read_text())
req=urllib.request.Request(cfg["master_url"].rstrip("/")+"/api/client/configs/pull", headers={"Accept":"application/json","Authorization":"Bearer "+cfg["agent_token"]})
with urllib.request.urlopen(req, timeout=30) as r: data=json.load(r)
f=next(x for x in data["forwards"] if int(x.get("forward_id",0))==47)
keys=["forward_id","mode","runtime_preference","backend_mode","transport","listen_port","target","sni","path_prefix","desired_latency_ms","helper_group_cap"]
print("summary", json.dumps({k:f.get(k) for k in keys}, indent=2))
print("bonding", json.dumps(f.get("bonding"), indent=2)[:4000])
print("mptcp", json.dumps(f.get("mptcp"), indent=2)[:4000])
print("candidate_pool")
for c in f.get("candidate_pool",[]):
 print(json.dumps({k:c.get(k) for k in ["candidate_kind","selected_primary","ready","runtime_capabilities","dial_host","dial_connect_ip","dial_port","cf_sni_host","target_sidecar_port","target_sidecar_bond_port","target_sidecar_mptcp_port","host","port","endpoint_id","measured_rtt_ms","origin_group_key","origin_group_order","ingress_auth_token"]}, ensure_ascii=False))
PY'