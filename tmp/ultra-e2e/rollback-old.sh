set -e
BK=$(ls -t /root/leikwan-backups/ultra-client.prev.* | head -1)
echo "rolling back to $BK"
sha256sum $BK | cut -c1-16
install -m 0755 $BK /usr/local/bin/ultra-client
echo -n 'version: '; /usr/local/bin/ultra-client --version
systemctl restart ultra-forward-170
sleep 45
systemctl is-active ultra-forward-170
python3 -c "import json;d=json.load(open('/run/ultra/forward-170.status.json'));print('state',d.get('state'),'attestation',(d.get('runtime_attestation') or {}).get('fingerprint'))"