set -e
NEW=/root/ultra-client.new
EXPECT=835ffd941596ff7f9717591051819fc2de78e2859298164a40af7a41a21ae977
sha=$(sha256sum $NEW | cut -d' ' -f1)
if [ "$sha" != "$EXPECT" ]; then echo "sha mismatch: $sha"; exit 1; fi
install -m 0755 $NEW /usr/local/bin/ultra-client
rm -f $NEW
echo -n 'installed: '; /usr/local/bin/ultra-client --version
systemctl restart ultra-forward-170; sleep 3
for f in 158 159 171 180; do systemctl restart ultra-forward-$f; sleep 1; done
echo 'settling 60s'; sleep 60
systemctl is-active ultra-forward-170