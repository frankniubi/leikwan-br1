echo '### ss -tinp sample'
ss -tinp 2>/dev/null | head -12
echo '### grep ultra-client'
ss -tinp 2>/dev/null | grep -A1 ultra-client | head -12
echo '### ss version'
ss -V