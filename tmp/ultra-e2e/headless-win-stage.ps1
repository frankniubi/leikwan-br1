$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
# re-publish the macOS payload so the bundle carries the final sources
foreach ($rid in @('osx-arm64','osx-x64')) {
  "### republish $rid"
  dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Mac\LeikwanBridge.Mac.csproj -c Release -r $rid --self-contained true -p:Version=1.0.33 -o ".build\mac-1033\publish-$rid" --nologo 2>&1 | Select-Object -Last 2
  if ($LASTEXITCODE -ne 0) { throw "publish failed: $rid" }
}
"### republish headless win-x64"
dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Headless\LeikwanBridge.Headless.csproj -c Release -r win-x64 --self-contained true -p:Version=0.1.20.47 -o .build\headless-win\publish --nologo 2>&1 | Select-Object -Last 2
if ($LASTEXITCODE -ne 0) { throw "headless publish failed" }
"### stage windows headless zip"
$stage = '.build\headless-win\stage\leikwan-bridge-headless'
Remove-Item -Recurse -Force .build\headless-win\stage -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $stage | Out-Null
Copy-Item '.build\headless-win\publish\*' $stage -Recurse -Force
Copy-Item install\install-windows-headless.ps1 $stage -Force
Copy-Item install\uninstall-windows-headless.ps1 $stage -Force
$zip = 'artifacts\windows\leikwan-bridge-headless-win-x64.zip'
Remove-Item -Force $zip -ErrorAction SilentlyContinue
Compress-Archive -Path $stage -DestinationPath $zip -CompressionLevel Optimal
$item = Get-Item $zip
'zip size=' + $item.Length
'zip sha=' + (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
$exe = Get-Item (Join-Path $stage 'leikwan-bridge-headless.exe')
'exe version=' + $exe.VersionInfo.FileVersion + ' product=' + $exe.VersionInfo.ProductVersion
'STAGE-DONE'
