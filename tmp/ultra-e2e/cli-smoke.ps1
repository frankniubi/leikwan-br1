$ErrorActionPreference='Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$exe = (Resolve-Path '.build\headless-win\stage\leikwan-bridge-headless\leikwan-bridge-headless.exe').Path
'### version'
& $exe --cli version
'version exit=' + $LASTEXITCODE
'### status'
$out = & $exe --cli status 2>&1 | Out-String
'status exit=' + $LASTEXITCODE
$out.Trim()
'### logs -n 3'
$out2 = & $exe --cli logs -n 3 2>&1 | Out-String
'logs exit=' + $LASTEXITCODE
$out2.Trim().Split("`n") | Select-Object -Last 4
'### doctor'
$out3 = & $exe --cli doctor 2>&1 | Out-String
'doctor exit=' + $LASTEXITCODE
$out3.Trim()
'### stop (no service registered: expect failure message)'
$out4 = & $exe --cli stop 2>&1 | Out-String
'stop exit=' + $LASTEXITCODE
$out4.Trim().Split("`n") | Select-Object -First 2
'CLI-SMOKE-DONE'
