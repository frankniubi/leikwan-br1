cat > /root/agent_probe.py <<'PY'
import importlib.util
import json
spec = importlib.util.spec_from_file_location('lw_agent', '/usr/local/bin/leikwan-client-agent.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
statuses = m.collect_ultra_status()
print('entries:', len(statuses))
for entry in statuses:
    print(' forward', entry.get('forward_id'), 'keys:', sorted(entry.keys()))
    print('   attestation:', json.dumps(entry.get('runtime_attestation')))
PY
python3 /root/agent_probe.py