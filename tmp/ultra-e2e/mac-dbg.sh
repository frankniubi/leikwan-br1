#!/bin/bash
export LC_ALL=C
cd ~/lkb-smoke
ls -l *.dmg
echo "--- raw attach output ---"
hdiutil attach -readonly -nobrowse -noverify arm64-1.0.32.dmg | cat -A | head -8
echo "--- volumes ---"
ls /Volumes
