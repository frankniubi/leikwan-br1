$ErrorActionPreference='Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$apk = (Resolve-Path 'artifacts\android\leikwan-android-client.apk').Path
$sdk = 'C:\Users\frankniubi\Android\Sdk'
$bt = Get-ChildItem (Join-Path $sdk 'build-tools') -Directory | Sort-Object { [Version]$_.Name } -Descending | Where-Object { Test-Path (Join-Path $_.FullName 'apksigner.bat') } | Select-Object -First 1
$apksigner = Join-Path $bt.FullName 'apksigner.bat'
'### apksigner verify'
$verify = (& $apksigner verify --verbose --min-sdk-version 26 $apk 2>&1 | Out-String)
$verify
'apksigner exit=' + $LASTEXITCODE
'### native artifacts'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\verify-android-native-artifacts.ps1 -Apk $apk 2>&1 | Select-Object -Last 1
'native exit=' + $LASTEXITCODE
'ANDROID-VERIFY-DONE'
