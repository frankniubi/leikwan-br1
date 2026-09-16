set -e
NEW=/root/ultra-client.new
EXPECT=76055872721d9a1a12a1a9ec432423a2b38e4a7cd28d6e855e19821fc92b6c9b
sha=$(sha256sum $NEW | cut -d' ' -f1)
if [ "$sha" != "$EXPECT" ]; then echo "sha mismatch: $sha"; exit 1; fi
mkdir -p /root/leikwan-backups
cp -a /usr/local/bin/ultra-client /root/leikwan-backups/ultra-client.prev.$(date -u +%Y%m%dT%H%M%SZ)
install -m 0755 $NEW /usr/local/bin/ultra-client
rm -f $NEW
echo -n 'installed sha: '; sha256sum /usr/local/bin/ultra-client | cut -d' ' -f1
echo -n 'reported version: '; /usr/local/bin/ultra-client --version
echo '--- restart forward 170 ---'
systemctl restart ultra-forward-170
sleep 4
systemctl is-active ultra-forward-170
ps -o pid,lstart,cmd -C ultra-client | grep 170 || true
echo '--- restart the other four (staggered) ---'
for f in 158 159 171 180; do systemctl restart ultra-forward-$f; sleep 2; printf '%s=%s ' $f $(systemctl is-active ultra-forward-$f); done; echo
echo '--- all running versions ---'
for f in 158 159 170 171 180; do printf 'forward-%s pid=' $f; systemctl show ultra-forward-$f -p MainPID --value; done