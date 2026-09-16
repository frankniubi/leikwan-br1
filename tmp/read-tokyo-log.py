#!/usr/bin/env python3
import pathlib
import re
import paramiko

root = pathlib.Path(__file__).resolve().parents[1]
text = (root / "leikwan-v3.txt").read_text(encoding="utf-8")
password = re.search(r'root@185\.220\.238\.143[^"\r\n]*"([^"]+)"', text).group(1)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("185.220.238.143", username="root", password=password, timeout=25,
            look_for_keys=False, allow_agent=False)
_, stdout, stderr = ssh.exec_command("ss -lntup 2>/dev/null | grep -E ':10001|:60000|:60001' || true; echo ---; grep -E '2026-09-16 14:1[3-9]:' /root/tokyo-echo.log | tail -n 40", timeout=30)
print(stdout.read().decode(errors="replace"))
err = stderr.read().decode(errors="replace")
if err:
    print(err)
ssh.close()
