echo '### iptables availability'
which iptables; iptables -S OUTPUT 2>&1 | head -12
echo '### existing chains'
iptables -S 2>/dev/null | grep -c . 