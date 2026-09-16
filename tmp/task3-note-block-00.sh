sudo systemctl list-units --type=service --all | grep -i ultra-source
systemctl cat ultra-source-agent | sed -n '1,25p'
sudo python3 - <<'PY'
import json
cfg = json.load(open('/etc/ultra-source-agent.json'))
for key in ('review_script', 'review_interval_seconds', 'review_big_bytes',
            'review_require_big_packet', 'max_reviews_per_cycle', 'state_dir'):
    print(key, '=', cfg.get(key))
PY
python3 -c "import sys; sys.path.insert(0, '/opt/ultra-source/runtime'); import native_udp as n; 
print('native_udp:', n.__file__); print('has unwrap_down:', hasattr(n, 'unwrap_down'))"
