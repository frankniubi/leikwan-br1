sudo systemctl stop ultra-source-agent
STAMP=$(sudo ls -1 /root/leikwan-source-agent-backups | sort | tail -1)   # or the stamp from step 1
echo "rolling back from /root/leikwan-source-agent-backups/$STAMP"
sudo cp -a "/root/leikwan-source-agent-backups/$STAMP/ultra_line_review.py" /opt/ultra-source/runtime/ultra_line_review.py
sudo cp -a "/root/leikwan-source-agent-backups/$STAMP/ultra_source_agent.py" /usr/local/bin/ultra-source-agent.py
sudo chmod 755 /opt/ultra-source/runtime/ultra_line_review.py /usr/local/bin/ultra-source-agent.py
sudo chown root:root /opt/ultra-source/runtime/ultra_line_review.py /usr/local/bin/ultra-source-agent.py
sudo systemctl start ultra-source-agent
