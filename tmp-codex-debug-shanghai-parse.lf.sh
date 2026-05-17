#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
python3 - <<'PY'
from pathlib import Path
raw=Path('shanghais.txt').read_bytes().replace(b'\r', b'\n').splitlines()
print('raw_count', len(raw))
for i,line in enumerate(raw):
    print(i, repr(line[:80]))
    if not line.strip():
        continue
    parts=line.decode('utf-8', errors='ignore').split('|')
    print('parts', len(parts), parts[:4])
PY
