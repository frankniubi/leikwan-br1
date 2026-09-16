echo '### sing-box ss-none config'
cat /etc/leikwan-shadowsocks-none/config.json
echo '### unit'
systemctl cat leikwan-shadowsocks-none.service 2>/dev/null | sed -n '1,30p'
echo '### echo servers installed?'
ls -l /root/*echo* /usr/local/bin/*echo* /opt/*echo* 2>/dev/null; ps aux | grep -iE 'echo|game_sim|jstc' | grep -v grep | head
echo '### iperf3 servers'
ss -lntup | grep -E '5201|60000|60001' || echo 'no echo/iperf listeners'
echo '### done'