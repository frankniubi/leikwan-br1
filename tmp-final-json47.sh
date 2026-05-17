set +e
python3 - <<'PY'
import json,pathlib
data=json.loads(pathlib.Path('/etc/leikwan/mptcp-forward-47.json').read_text())
print({k:data.get(k) for k in ['forward_id','listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode']})
print('endpoints', [(e.get('endpoint_id'), e.get('host'), e.get('last_latency_ms'), e.get('leg_role')) for e in data.get('endpoints',[])])
PY