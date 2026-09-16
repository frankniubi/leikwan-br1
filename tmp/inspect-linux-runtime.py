#!/usr/bin/env python3
import pathlib
import re
import shlex
import paramiko

root = pathlib.Path(__file__).resolve().parents[1]
text = (root / "leikwan-v3.txt").read_text(encoding="utf-8")
password = re.search(r'root@172\.21\.255\.189[^"\r\n]*"([^"]+)"', text).group(1)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("172.21.255.189", username="root", password=password, timeout=25,
            look_for_keys=False, allow_agent=False)
command = r'''set -eu
pid=$(systemctl show -p MainPID --value ultra-forward-170)
echo "PID=$pid"
tr '\0' ' ' < /proc/$pid/cmdline; echo
echo '--- fds ---'
ls -l /proc/$pid/fd 2>/dev/null | tail -n 20 || true
echo '--- tokyo-like target sockets from test host ---'
ss -lntup 2>/dev/null | grep -E ':10001|:60000|:60001' || true
echo '--- likely configs ---'
for p in /run/ultra/forward-170* /var/lib/ultra/forward-170* /etc/ultra/forward-170* /etc/leikwan/*170* /tmp/ultra*; do
  if [ -f "$p" ]; then
    echo "FILE=$p"
    sed -E 's/("(user|password|credential|session_ticket|token|secret)"[[:space:]]*:[[:space:]]*")[^"]*(")/\1<redacted>\3/Ig' "$p" | head -n 160
  fi
done
echo '--- recent unit journal (diagnostic, no secrets expected) ---'
journalctl -u ultra-forward-170 --since '-20 min' --no-pager 2>/dev/null | grep -E -i 'no_healthy|candidate|probe|ceiling|keepalive|failed|forward_id' | tail -n 260 || true
'''
_, stdout, stderr = ssh.exec_command("bash -lc " + shlex.quote(command), timeout=30)
print(stdout.read().decode(errors="replace"))
err = stderr.read().decode(errors="replace")
if err:
    print(err)
ssh.close()
