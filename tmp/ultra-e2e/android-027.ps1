$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
"### android packaging"
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\package-android-native-test.ps1 -OutputPath artifacts\android\leikwan-android-client.apk -RuntimeVersion 2026.09.13.3 -SkipRuntimeBuild 2>&1 | Select-Object -Last 40
"android exit=$LASTEXITCODE"
"ANDROID-DONE"
