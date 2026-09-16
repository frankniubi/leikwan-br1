sudo journalctl -u ultra-source-agent --since "-40 min" --no-pager | grep -E "review [0-9]+ line" | tail -5
