import pathlib
import re
import sys
import time

import paramiko


notes = pathlib.Path("leikwan-v3.txt").read_text(encoding="utf-8")
match = re.search(r"LIVE panel SSH:\s*root@([\d.]+),\s*[^\"\r\n]*\"([^\"]+)\"", notes)
if not match:
    raise SystemExit("cannot parse panel credentials")
host, password = match.groups()
local_path = pathlib.Path(sys.argv[1])
remote_path = sys.argv[2]
transport = paramiko.Transport((host, 22), default_window_size=16 * 1024 * 1024,
                               default_max_packet_size=4 * 1024 * 1024)
transport.connect(username="root", password=password)
sftp = paramiko.SFTPClient.from_transport(transport, window_size=16 * 1024 * 1024,
                                           max_packet_size=4 * 1024 * 1024)
started = time.monotonic()
sent = 0
try:
    with local_path.open("rb") as source, sftp.file(remote_path, "wb") as target:
        target.set_pipelined(True)
        while True:
            chunk = source.read(1024 * 1024)
            if not chunk:
                break
            target.write(chunk)
            sent += len(chunk)
            if sent % (32 * 1024 * 1024) < len(chunk):
                elapsed = max(time.monotonic() - started, 0.001)
                print(f"sent={sent} rate={sent / elapsed / 1024 / 1024:.2f}MiB/s", flush=True)
        target.flush()
finally:
    sftp.close()
    transport.close()
elapsed = max(time.monotonic() - started, 0.001)
print(f"uploaded={sent} rate={sent / elapsed / 1024 / 1024:.2f}MiB/s")
