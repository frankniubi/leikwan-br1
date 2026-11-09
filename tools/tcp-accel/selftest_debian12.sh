#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

python3 -m py_compile tools/tcp-accel/tcp_accel.py tools/tcp-accel/test_tcp_accel.py
python3 -m unittest discover -s tools/tcp-accel -p 'test_*.py' -v
python3 -m json.tool tools/tcp-accel/examples/client-direct.json >/dev/null
python3 -m json.tool tools/tcp-accel/examples/client-through-bridge.json >/dev/null
python3 -m json.tool tools/tcp-accel/examples/client-map.json >/dev/null
python3 -m json.tool tools/tcp-accel/examples/bridge.json >/dev/null
bash -n tools/tcp-accel/install_debian12.sh

python3 - "$tmpdir/client-check.json" <<'PY'
import json
import sys
from pathlib import Path
src = Path('tools/tcp-accel/examples/client-direct.json')
out = Path(sys.argv[1])
config = json.loads(src.read_text(encoding='utf-8-sig'))
config.pop('token_file', None)
config['token'] = 'selftest-token'
out.write_text(json.dumps(config), encoding='utf-8')
PY
python3 tools/tcp-accel/tcp_accel.py check-client-config --config "$tmpdir/client-check.json" >/dev/null

cat <<'OK'
SELFTEST_OK
- Python syntax OK
- unittest OK
- JSON examples OK
- installer bash syntax OK
- check-client-config OK
OK