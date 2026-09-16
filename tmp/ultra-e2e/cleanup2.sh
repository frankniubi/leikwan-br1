echo '### soak unit'
systemctl is-active ultra-soak.service 2>/dev/null; systemctl reset-failed ultra-soak.service 2>/dev/null; echo cleaned
echo '### forward state'
sha256sum /usr/local/bin/ultra-client | cut -c1-16; /usr/local/bin/ultra-client --version
for f in 158 159 170 171 180; do printf '%s ' $(systemctl is-active ultra-forward-$f); done; echo
echo '### stop the temporary echo pair on the peer'
systemctl stop tokyo-echo.service 2>/dev/null; systemctl reset-failed tokyo-echo.service 2>/dev/null; echo echo-stopped
echo '### failed units left'
systemctl --failed --no-pager 2>/dev/null | tail -2