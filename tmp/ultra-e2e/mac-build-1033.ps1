$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$rc = (Resolve-Path '.tmp\mac-android-release-20260906\rcodesign\apple-codesign-0.29.0-x86_64-pc-windows-msvc\rcodesign.exe').Path
foreach ($rid in @('osx-arm64','osx-x64')) {
  "### republish $rid"
  dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Mac\LeikwanBridge.Mac.csproj -c Release -r $rid --self-contained true -p:Version=1.0.33 -o ".build\mac-1033\publish-$rid" --nologo 2>&1 | Select-Object -Last 2
  if ($LASTEXITCODE -ne 0) { throw "publish failed: $rid" }
}
"### republish headless win-x64 and restage"
dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Headless\LeikwanBridge.Headless.csproj -c Release -r win-x64 --self-contained true -p:Version=0.1.20.47 -o .build\headless-win\publish --nologo 2>&1 | Select-Object -Last 2
if ($LASTEXITCODE -ne 0) { throw "headless publish failed" }
$stage = '.build\headless-win\stage\leikwan-bridge-headless'
Remove-Item -Recurse -Force .build\headless-win\stage -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $stage | Out-Null
Copy-Item '.build\headless-win\publish\*' $stage -Recurse -Force
Copy-Item install\install-windows-headless.ps1 $stage -Force
Copy-Item install\uninstall-windows-headless.ps1 $stage -Force
$zip = 'artifacts\windows\leikwan-bridge-headless-win-x64.zip'
Remove-Item -Force $zip -ErrorAction SilentlyContinue
Compress-Archive -Path $stage -DestinationPath $zip -CompressionLevel Optimal
'zip size=' + (Get-Item $zip).Length + ' sha=' + (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
foreach ($pair in @(@('arm64','osx-arm64'), @('x64','osx-x64'))) {
  $arch = $pair[0]
  Remove-Item -Recurse -Force ".build\mac-1033\stage-$arch" -ErrorAction SilentlyContinue
  "### $arch bundle"
  python scripts\build-macos-client-bundle.py --base-zip "artifacts\macos\leikwan-bridge-update-$arch.zip" --publish-dir ".build\mac-1033\publish-$($pair[1])" --runtime ".build\mac-1032\signed\rt-$arch" --rcodesign $rc --arch $arch --version 1.0.33 --stage ".build\mac-1033\stage-$arch" --output-zip ".build\mac-1033\out\leikwan-bridge-update-$arch.zip" --report ".build\mac-1033\report-$arch.json" 2>&1 | Select-Object -Last 8
  "$arch exit=$LASTEXITCODE"
  python scripts\verify-macos-update-zip.py ".build\mac-1033\out\leikwan-bridge-update-$arch.zip" --runtime-artifact ".build\mac-1032\signed\rt-$arch" --version 1.0.33 --architecture $(if ($arch -eq 'arm64') { 'arm64' } else { 'x86_64' }) 2>&1 | Select-Object -Last 9
  "$arch verify exit=$LASTEXITCODE"
}
"BUILD-1033-DONE"
