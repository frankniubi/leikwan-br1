set -euo pipefail
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@114.80.33.50 'cat > /tmp/decode_token.py <<"PY"
import base64,json,hmac,hashlib,re,pathlib
s=pathlib.Path("/usr/local/bin/leikwan-client-start-47.sh").read_text()
m=re.search(r"--session-token '\''([^'\'']+)'\''", s)
if not m:
    m=re.search(r"--session-token ([^ \\n]+)", s)
print("token_found", bool(m))
tok=m.group(1) if m else ""
if tok:
    p,sig=tok.split(".",1)
    data=base64.urlsafe_b64decode(p + "="*((4-len(p)%4)%4))
    print(data.decode())
    for secret in ["1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0","bond-default","secret-123"]:
        exp=base64.urlsafe_b64encode(hmac.new(secret.encode(), data, hashlib.sha256).digest()).decode().rstrip("=")
        print(secret[:8], exp==sig, exp[:12], sig[:12])
PY
python3 /tmp/decode_token.py'
