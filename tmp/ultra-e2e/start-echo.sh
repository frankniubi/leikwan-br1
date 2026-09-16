pkill -f tokyo-echo.py 2>/dev/null; sleep 0.3
cp /root/tokyo-echo.py /root/tokyo-echo.py.run
setsid nohup python3 /root/tokyo-echo.py > /root/tokyo-echo.out 2>&1 < /dev/null &
sleep 2
ss -lntup | grep -E '60000|60001' || echo 'NOT LISTENING'
tail -n 3 /root/tokyo-echo.out