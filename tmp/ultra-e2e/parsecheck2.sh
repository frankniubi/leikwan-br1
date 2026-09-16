echo '### state counts'
ss -tinp 2>/dev/null | grep -A1 ultra-client | grep -oE '^(ESTAB|CLOSE-WAIT|TIME-WAIT|FIN-WAIT-[12]|SYN-SENT)' | sort | uniq -c
echo '### parse result'
python3 /tmp/parse.py
echo '### established ultra sockets only'
ss -tinp state established 2>/dev/null | grep ultra-client | head -5