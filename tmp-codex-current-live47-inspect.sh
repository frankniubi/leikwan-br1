#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  echo "=== client time/host ==="; hostname; date -Is; uname -a
  echo "=== service active ==="; systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
  echo "=== versions/hashes ===";
  grep -n "AGENT_VERSION" /usr/local/bin/leikwan-client-agent.py 2>/dev/null | head -3 || true
  sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json /var/lib/leikwan-client-agent/leikwan-mptcp-relay 2>/dev/null || true
  echo "=== runtime json summary ===";
  python3 - <<"PY"
import json, pathlib, re
for p in ["/etc/leikwan/mptcp-forward-47.json", "/var/lib/leikwan-client-agent/applied-forwards.json", "/var/lib/leikwan-client-agent/self-leg-best-ip-cache.json", "/var/lib/leikwan-client-agent/bond-endpoint-health.json", "/run/leikwan/bond-leg-health-forward-47.json"]:
    path=pathlib.Path(p)
    print("---", p)
    if not path.exists():
        print("MISSING"); continue
    try:
        data=json.loads(path.read_text())
        if p.endswith("mptcp-forward-47.json"):
            keys=["forward_id","listen_port","transport","remote_host","remote_port","relay_host","relay_port","bond_mode","direct_legs","subflow_limit","policy_mode","endpoints","mptcp_endpoints","underlays"]
            print(json.dumps({k:data.get(k) for k in keys}, indent=2, ensure_ascii=False)[:12000])
        elif p.endswith("bond-endpoint-health.json"):
            print(json.dumps(data.get("47", data), indent=2, ensure_ascii=False)[:8000])
        else:
            print(json.dumps(data, indent=2, ensure_ascii=False)[:8000])
    except Exception as e:
        print("ERR", repr(e)); print(path.read_text(errors="ignore")[:2000])
script=pathlib.Path("/usr/local/bin/leikwan-client-start-47.sh")
print("--- script wss/direct/legs")
if script.exists():
    text=script.read_text(errors="ignore")
    for line in text.splitlines():
        if any(s in line for s in ["wstunnel client", "leikwan-mptcp-relay", "leikwan-bond", "wss://", "leikwan-ingress", "RUST_RELAY"]): print(line)
PY
  echo "=== processes/listeners ===";
  ps -ef | grep -E "leikwan-client-start-47|leikwan-mptcp-relay client --config /etc/leikwan/mptcp-forward-47|wstunnel client -L tcp://127.0.0.1:|leikwan-bond client|leikwan-ingress client" | grep -v grep || true
  ss -ltnp | grep -E ":(11035|39559|21739|21740|7000|2053|8898|8899)" || true
  echo "=== recent f47 logs ===";
  journalctl -u leikwan-client-47.service --since "-20 min" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|520|connected|accepted local|ERROR|WARN|error|failed" | tail -220 || true
  echo "=== recent agent 47/update logs ===";
  journalctl -u leikwan-client-agent.service --since "-20 min" --no-pager -o short-iso | grep -E "forward 47|self-leg|self leg|requested client self-update|restarted leikwan-client-47|refreshed leikwan-client-47|ERROR|WARNING" | tail -180 || true
'
