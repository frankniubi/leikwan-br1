echo '### wait for the fresh runtime to get a lease + probe cycle'
sleep 25
echo '### selftest'
timeout 90 python3 /root/soak.py --selftest
echo "selftest rc=$?"
echo '### listener check'
ss -lntup | grep -E ':10001' | head -5