$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
'### republish headless win-x64 with the shutdown fix'
dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Headless\LeikwanBridge.Headless.csproj -c Release -r win-x64 --self-contained true -p:Version=0.1.20.47 -o .build\headless-win\publish --nologo 2>&1 | Select-Object -Last 2
'publish exit=' + $LASTEXITCODE
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
'### smoke: bad token must exit 1 without an unhandled exception'
$exe = (Resolve-Path (Join-Path $stage 'leikwan-bridge-headless.exe')).Path
$status = (Join-Path (Get-Location) '.build\headless-win\status2.json')
$out = & $exe --headless --panel https://v3.leikwanhost.com --client-id 1 --agent-token invalid-token-for-smoke --once --once-window 5 --status-file $status 2>&1 | Out-String
'bad token exit=' + $LASTEXITCODE
if ($out -match 'Unhandled exception') { 'UNHANDLED-EXCEPTION-PRESENT' } else { 'clean exit (no unhandled exception)' }
($out -split "`n") | Select-Object -First 2
'SMOKE3-DONE'
