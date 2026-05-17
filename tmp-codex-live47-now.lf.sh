#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
 echo "=== CLIENT $(hostname) $(date -Is) ==="
 echo "--- service active ---"; systemctl is-active leikwan-client-agent.service leikwan-client-47.service || true
 echo "--- versions sha ---"; grep -n "AGENT_VERSION" /usr/local/bin/leikwan-client-agent.py 2>/dev/null | head -3; sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/leikwan-mptcp-relay /usr/local/bin/wstunnel 2>/dev/null || true; file /usr/local/bin/leikwan-mptcp-relay 2>/dev/null || true
 echo "--- mptcp config summary ---"; python3 - <<'"'"'PY'"'"'
import json, pathlib
for p in ["/etc/leikwan/mptcp-forward-47.json","/var/lib/leikwan-client-agent/applied_forwards.json","/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json","/var/lib/leikwan-client-agent/bond-endpoint-health.json"]:
    print("###", p)
    try:
        data = json.loads(pathlib.Path(p).read_text())
        if p.endswith("mptcp-forward-47.json"):
            out = {k: data.get(k) for k in ["listen_port","remote_host","remote_port","bond_mode","relay_host","relay_port","direct_legs","endpoints","mptcp_endpoints"]}
            print(json.dumps(out, ensure_ascii=False, indent=2)[:6000])
        else:
            s = json.dumps(data, ensure_ascii=False, indent=2)
            print(s[:6000])
    except Exception as e:
        print("ERR", repr(e))
PY
 echo "--- start script ---"; sed -n "1,220p" /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
 echo "--- listeners/procs ---"; ss -ltnp | grep -E ":(11035|39559|8898|8899|2053)" || true; ps -ef | grep -E "leikwan-client-start-47|mptcp-forward-47|39559|11035|leikwan-mptcp-relay|wstunnel client" | grep -v grep || true
 echo "--- service logs last 12min key ---"; journalctl -u leikwan-client-47.service --since "-12 min" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|session established|accepted downstream|Opening TCP connection|Invalid status code|520|WARN|ERROR|failed|local_write_failed|recv_reset" | tail -240 || true
 echo "--- full recent tail ---"; journalctl -u leikwan-client-47.service --since "-3 min" --no-pager -o short-iso | tail -160 || true
'