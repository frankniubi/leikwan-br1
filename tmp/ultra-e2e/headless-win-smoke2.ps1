$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$exe = (Resolve-Path '.build\headless-win\publish\leikwan-bridge-headless.exe').Path
$status = (Join-Path (Get-Location) '.build\headless-win\status.json')
'### smoke: bad token (bounded retries)'
$sw = [Diagnostics.Stopwatch]::StartNew()
$out = & $exe --headless --panel https://v3.leikwanhost.com --client-id 1 --agent-token invalid-token-for-smoke --once --once-window 5 --status-file $status 2>&1 | Out-String
'bad token exit=' + $LASTEXITCODE + ' elapsed=' + [int]$sw.Elapsed.TotalSeconds + 's'
($out -split "`n") | Select-Object -First 4
'### log tail'
$log = Get-ChildItem "$env:ProgramData\Leikwan\ClientAgent\logs\headless-*.log" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($log) { $log.FullName; Get-Content $log.FullName -Tail 8 } else { 'no log written' }
'HEADLESS-SMOKE2-DONE'
