$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$rc = (Resolve-Path '.tmp\mac-android-release-20260906\rcodesign\apple-codesign-0.29.0-x86_64-pc-windows-msvc\rcodesign.exe').Path
foreach ($pair in @(@('arm64','rt-arm64'), @('x64','rt-x64'))) {
  $arch = $pair[0]; $rt = $pair[1]
  Remove-Item -Recurse -Force ".build\mac-1032\stage2-$arch" -ErrorAction SilentlyContinue
  "### $arch bundle pass 2"
  python scripts\build-macos-client-bundle.py --base-zip "artifacts\macos\leikwan-bridge-update-$arch.zip" --publish-dir ".build\mac-1032\publish-osx-$arch" --runtime ".build\mac-1032\signed\$rt" --rcodesign $rc --arch $arch --version 1.0.32 --stage ".build\mac-1032\stage2-$arch" --output-zip ".build\mac-1032\out\leikwan-bridge-update-$arch.zip" --report ".build\mac-1032\report2-$arch.json" 2>&1 | Select-Object -Last 22
  "$arch exit=$LASTEXITCODE"
}
"PASS2-DONE"
