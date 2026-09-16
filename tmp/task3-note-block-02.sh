sudo bash -c 'BASE=a9cc8b87b1bebf8a370bda023c9981ec; NEW=8cae05e5153bb75a8219ef886ea55b1a; P=/opt/ultra-source/runtime/ultra_line_review.py; 
GOT=$(md5sum "$P" | cut -d" " -f1); echo "installed probe md5: $GOT"; 
if [ "$GOT" = "$NEW" ]; then echo "already on the new probe - nothing to install"; exit 0; fi; 
if [ "$GOT" != "$BASE" ]; then echo "REFUSING: unknown probe revision - back it up and report it"; exit 1; fi; 
echo "OK: documented baseline detected, safe to install"'

# the agent file: record what is there, and only continue if it looks like the agent
sudo bash -c 'A=/usr/local/bin/ultra-source-agent.py; 
echo "installed agent md5: $(md5sum "$A" | cut -d" " -f1)"; 
grep -n "^AGENT_VERSION" "$A"; 
grep -c "def run_review" "$A"; 
grep -c "def process_reviews" "$A"'
