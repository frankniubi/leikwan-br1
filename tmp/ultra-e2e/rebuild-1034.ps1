$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$rc = (Resolve-Path '.tmp\mac-android-release-20260906\rcodesign\apple-codesign-0.29.0-x86_64-pc-windows-msvc\rcodesign.exe').Path
foreach ($pair in @(@('arm64','osx-arm64'), @('x64','osx-x64'))) {
  "### republish $($pair[1])"
  dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Mac\LeikwanBridge.Mac.csproj -c Release -r $pair[1] --self-contained true -p:Version=1.0.34 -o ".build\mac-1034\publish-$($pair[1])" --nologo 2>&1 | Select-Object -Last 1
  if ($LASTEXITCODE -ne 0) { throw "publish failed: $($pair[1])" }
  $arch = $pair[0]
  Remove-Item -Recurse -Force ".build\mac-1034\stage-$arch" -ErrorAction SilentlyContinue
  python scripts\build-macos-client-bundle.py --base-zip "artifacts\macos\leikwan-bridge-update-$arch.zip" --publish-dir ".build\mac-1034\publish-$($pair[1])" --runtime ".build\mac-1032\signed\rt-$arch" --rcodesign $rc --arch $arch --version 1.0.34 --stage ".build\mac-1034\stage-$arch" --output-zip ".build\mac-1034\out\leikwan-bridge-update-$arch.zip" --report ".build\mac-1034\report-$arch.json" 2>&1 | Select-Object -Last 2
  "$arch bundle exit=$LASTEXITCODE"
  python scripts\verify-macos-update-zip.py ".build\mac-1034\out\leikwan-bridge-update-$arch.zip" --runtime-artifact ".build\mac-1032\signed\rt-$arch" --version 1.0.34 --architecture $(if ($arch -eq 'arm64') { 'arm64' } else { 'x86_64' }) 2>&1 | Select-Object -Last 7
  "$arch verify exit=$LASTEXITCODE"
}
'REBUILD-1034-DONE'
