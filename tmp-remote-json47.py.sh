set +e
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
data=json.loads(p.read_text())
print('summary', {k:data.get(k) for k in ['forward_id','listen_port','transport','remote_host','remote_port','relay_host','relay_port','bond_mode','speed_limit_mbps','policy_mode']})
print('direct_legs', data.get('direct_legs'))
print('endpoints:')
for e in data.get('endpoints',[]):
    keys=['endpoint_id','host','port','cdn_host','ingress_protocol','health_role','quality_score','last_latency_ms','dynamic_priority','leg_role','priority','weight']
    print({k:e.get(k) for k in keys})
print('underlays:')
for u in data.get('underlays',[]):
    print({k:u.get(k) for k in ['interface','local_address','peer_address','listen_port','remote_host','remote_port','endpoint_id','endpoint_host','endpoint_port','ingress_protocol']})
PY