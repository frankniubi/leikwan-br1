$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
'### macos build (full output tail)'
& scripts\build-ultra-macos.ps1 -Version 2026.09.13.3 -ShortSha 452c8fe8 2>&1 | Select-Object -Last 25
'macos exit=' + $LASTEXITCODE
'### android x86_64'
& scripts\build-ultra-android.ps1 -Version 2026.09.13.3 -ShortSha 452c8fe8 -Abi x86_64 2>&1 | Select-Object -Last 6
'android exit=' + $LASTEXITCODE
'### stamps'
python tmp\ultra-e2e\scan-fingerprints.py
