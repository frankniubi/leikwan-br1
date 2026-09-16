sha256sum /opt/ultra-source/runtime/ultra_line_review.py /usr/local/bin/ultra-source-agent.py
sudo python3 -m py_compile /opt/ultra-source/runtime/ultra_line_review.py /usr/local/bin/ultra-source-agent.py
python3 /opt/ultra-source/runtime/ultra_line_review.py --help | head -20
