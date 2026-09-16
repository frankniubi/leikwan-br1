echo '### soak.py compile'
python3 -m py_compile /root/soak.py && echo 'soak.py OK'
echo '### runtime now'
sha256sum /usr/local/bin/ultra-client; /usr/local/bin/ultra-client --version
echo '### update unit'
systemctl list-units --all --no-pager 2>/dev/null | grep 'leikwan-client-agent-update' | tail -2
journalctl -u 'leikwan-client-agent-update-*.service' --no-pager -n 20 2>/dev/null | tail -20
echo '### agent recent'
journalctl -u leikwan-client-agent --no-pager -n 12 2>/dev/null | tail -12