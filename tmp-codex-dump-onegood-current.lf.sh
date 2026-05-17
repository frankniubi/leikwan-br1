#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is)"
echo '--- onegood script ---'; sed -n '1,220p' /usr/local/bin/leikwan-client-start-47-onegood.sh
echo '--- processes ---'; ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-client-47-onegood|leikwan-bond client --protocol tcp --listen 127.0.0.1:11035|leikwan-ingress client --listen 127.0.0.1:22740' | grep -v grep
echo '--- token decode ---'; python3 - <<'PY'
import pathlib,re,base64,json
text=pathlib.Path('/usr/local/bin/leikwan-client-start-47-onegood.sh').read_text()
# generated at runtime; decode current process cmdline token
import glob
for f in glob.glob('/proc/[0-9]*/cmdline'):
    raw=pathlib.Path(f).read_bytes().replace(b'\0',b' ').decode('latin1','ignore')
    if 'leikwan-bond client' in raw and '11035' in raw and '--session-token' in raw:
        parts=raw.split(); tok=parts[parts.index('--session-token')+1]; p=tok.split('.')[0]+'='*((4-len(tok.split('.')[0])%4)%4); print(json.loads(base64.urlsafe_b64decode(p)))
PY
echo '--- recent all onegood logs ---'; journalctl -u leikwan-client-47-onegood.service --since '-30 min' --no-pager -o short-iso | tail -220
REMOTE
