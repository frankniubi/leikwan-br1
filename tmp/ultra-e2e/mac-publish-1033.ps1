$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
New-Item -ItemType Directory -Force -Path .build\mac-1033 | Out-Null
foreach ($rid in @('osx-arm64','osx-x64')) {
  "### publish $rid"
  dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Mac\LeikwanBridge.Mac.csproj -c Release -r $rid --self-contained true -p:Version=1.0.33 -o ".build\mac-1033\publish-$rid" --nologo 2>&1 | Select-Object -Last 3
  if ($LASTEXITCODE -ne 0) { throw "publish failed: $rid" }
}
"PUBLISH-1033-DONE"
