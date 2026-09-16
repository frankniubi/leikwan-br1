#!/bin/bash
export LC_ALL=C
ssh -i ~/.ssh/leikwan_macos_build -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=12 leikwan@100.103.253.21 'sw_vers -productVersion; uname -m; id -un; ls -d /Applications/*.app 2>/dev/null | head -5' 2>&1 | tail -12
echo "EXIT=$?"
