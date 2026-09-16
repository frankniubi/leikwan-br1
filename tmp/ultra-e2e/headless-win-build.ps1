$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
'### publish headless win-x64'
dotnet publish LeikwanBridge.Desktop\src\LeikwanBridge.Headless\LeikwanBridge.Headless.csproj -c Release -r win-x64 --self-contained true -p:Version=0.1.20.47 -o .build\headless-win\publish --nologo 2>&1 | Select-Object -Last 3
'publish exit=' + $LASTEXITCODE
$exe = '.build\headless-win\publish\leikwan-bridge-headless.exe'
'exe exists: ' + (Test-Path $exe)
'exe size: ' + (Get-Item $exe).Length
'### smoke: invalid arguments'
$out = & $exe --headless --client-id 0 2>&1 | Out-String
'invalid exit=' + $LASTEXITCODE
$out.Trim()
'### smoke: usage on unknown flag'
$out2 = & $exe --headless --client-id 7 --agent-token x --bogus 2>&1 | Out-String
'unknown exit=' + $LASTEXITCODE
$out2.Trim().Split("`n")[0]
'### smoke: bad token retries then fails'
$sw = [Diagnostics.Stopwatch]::StartNew()
$out3 = & $exe --headless --panel https://v3.leikwanhost.com --client-id 1 --agent-token invalid-token-for-smoke --once --once-window 5 --status-file .build\headless-win\status.json 2>&1 | Out-String
'bad token exit=' + $LASTEXITCODE + ' elapsed=' + [int]$sw.Elapsed.TotalSeconds + 's'
$out3.Trim().Split("`n") | Select-Object -First 6
'### log tail'
$log = Get-ChildItem "$env:ProgramData\Leikwan\ClientAgent\logs\headless-*.log" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($log) { $log.FullName; Get-Content $log.FullName -Tail 6 }
'HEADLESS-SMOKE-DONE'
