$ErrorActionPreference='Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
"### verify arm64"
python scripts\verify-macos-update-zip.py .build\mac-1032\out\leikwan-bridge-update-arm64.zip --runtime-artifact .build\mac-1032\signed\rt-arm64 --version 1.0.32 --architecture arm64 2>&1 | Select-Object -Last 12
"arm64 exit=$LASTEXITCODE"
"### verify x64"
python scripts\verify-macos-update-zip.py .build\mac-1032\out\leikwan-bridge-update-x64.zip --runtime-artifact .build\mac-1032\signed\rt-x64 --version 1.0.32 --architecture x86_64 2>&1 | Select-Object -Last 12
"x64 exit=$LASTEXITCODE"
"VERIFY-DONE"
