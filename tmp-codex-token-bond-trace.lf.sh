#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
cat >/tmp/codex_trace_token.py <<'PY'
import base64, json, sys, pathlib, re
candidates=[]
for path in ['/usr/local/bin/leikwan-client-start-47-onegood.sh','/usr/local/bin/leikwan-client-start-47.sh']:
    try: text=pathlib.Path(path).read_text(errors='ignore')
    except: continue
    for m in re.finditer(r'--session-token\s+["\']?([^"\'\s]+)', text): candidates.append((path,m.group(1)))
    for m in re.finditer(r'TOKEN=([^\n]+)', text): pass
# Add currently running cmdline tokens
import glob
for f in glob.glob('/proc/[0-9]*/cmdline'):
    try: raw=pathlib.Path(f).read_bytes().replace(b'\0',b' ').decode('latin1','ignore')
    except: continue
    if 'leikwan-bond client' in raw and '--session-token' in raw:
        parts=raw.split()
        if '--session-token' in parts:
            candidates.append((f, parts[parts.index('--session-token')+1]))

def dec(tok):
    p=tok.split('.')[0]
    p += '='*((4-len(p)%4)%4)
    return json.loads(base64.urlsafe_b64decode(p))
for src,tok in candidates:
    try: payload=dec(tok)
    except Exception as e: payload={'err':repr(e)}
    print(src, payload)
PY
python3 /tmp/codex_trace_token.py
REMOTE
