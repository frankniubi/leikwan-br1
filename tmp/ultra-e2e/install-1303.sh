set -e
NEW=/root/ultra-client.new
EXPECT=835ffd941596ff7f9717591051819fc2de78e2859298164a40af7a41a21ae977
sha=$(sha256sum $NEW | cut -d' ' -f1)
if [ "$sha" != "$EXPECT" ]; then echo "sha mismatch: $sha"; exit 1; fi
mkdir -p /root/leikwan-backups
cp -a /usr/local/bin/ultra-client /root/leikwan-backups/ultra-client.prev.$(date -u +%Y%m%dT%H%M%SZ)
install -m 0755 $NEW /usr/local/bin/ultra-client
rm -f $NEW
echo -n 'installed: '; sha256sum /usr/local/bin/ultra-client | cut -c1-16
echo -n 'version: '; /usr/local/bin/ultra-client --version
systemctl restart ultra-forward-170; sleep 3
for f in 158 159 171 180; do systemctl restart ultra-forward-$f; sleep 1; done
for f in 158 159 170 171 180; do printf '%s=%s ' $f $(systemctl is-active ultra-forward-$f); done; echo
echo 'waiting 60s for the fresh runtime to settle (lease + first probe)'
sleep 60
python3 -c "import json;d=json.load(open('/run/ultra/forward-170.status.json'));print('state',d.get('state'),'attestation',d.get('runtime_attestation'))"