set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
sshpass -p '@XwDQ6H5mgMXbrGD' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@47.116.11.144 'python3 - <<"PY"
import pathlib
for p in ["/usr/local/bin/leikwan-slave-agent.py","/var/lib/leikwan-slave/cf-location-best-ip.json","/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json","/var/lib/leikwan-slave/listeners/routes-7000.json","/etc/leikwan-slave/config.json"]:
    print("---", p)
    path=pathlib.Path(p)
    if path.exists():
        data=path.read_text(errors="ignore")
        print(data[:4000])
    else:
        print("missing")
PY'
