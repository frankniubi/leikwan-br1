STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/root/leikwan-source-agent-backups/$STAMP
sudo mkdir -p "$BACKUP"
sudo cp -a /opt/ultra-source/runtime/ultra_line_review.py "$BACKUP/ultra_line_review.py"
sudo cp -a /usr/local/bin/ultra-source-agent.py "$BACKUP/ultra_source_agent.py"
sudo sha256sum "$BACKUP/ultra_line_review.py" "$BACKUP/ultra_source_agent.py" | sudo tee "$BACKUP/SHA256SUMS"
sudo ls -l "$BACKUP"
echo "BACKUP_DIR=$BACKUP"
