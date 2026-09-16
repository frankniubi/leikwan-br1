$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$rc = (Resolve-Path '.tmp\mac-android-release-20260906\rcodesign\apple-codesign-0.29.0-x86_64-pc-windows-msvc\rcodesign.exe').Path
Remove-Item -Recurse -Force .build\mac-1032\stage-arm64 -ErrorAction SilentlyContinue
"### arm64 bundle"
python scripts\build-macos-client-bundle.py --base-zip artifacts\macos\leikwan-bridge-update-arm64.zip --publish-dir .build\mac-1032\publish-osx-arm64 --runtime artifacts\ultra\ultra-client_macos_arm64 --rcodesign $rc --arch arm64 --version 1.0.32 --stage .build\mac-1032\stage-arm64 --output-zip .build\mac-1032\out\leikwan-bridge-update-arm64.zip --report .build\mac-1032\report-arm64.json 2>&1 | Select-Object -Last 30
"arm64 exit=$LASTEXITCODE"
"### x64 bundle"
Remove-Item -Recurse -Force .build\mac-1032\stage-x64 -ErrorAction SilentlyContinue
python scripts\build-macos-client-bundle.py --base-zip artifacts\macos\leikwan-bridge-update-x64.zip --publish-dir .build\mac-1032\publish-osx-x64 --runtime artifacts\ultra\ultra-client_macos_amd64 --rcodesign $rc --arch x64 --version 1.0.32 --stage .build\mac-1032\stage-x64 --output-zip .build\mac-1032\out\leikwan-bridge-update-x64.zip --report .build\mac-1032\report-x64.json 2>&1 | Select-Object -Last 30
"x64 exit=$LASTEXITCODE"
"BUILD-DONE"
