cat > /root/dryparse.py <<'PY'
import importlib.util
spec = importlib.util.spec_from_file_location('d', '/root/switch-driver2.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
print(m.acked_by_peer())
PY
python3 /root/dryparse.py