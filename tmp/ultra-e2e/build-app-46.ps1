$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
'### dotnet publish'
dotnet publish 'LeikwanBridge.Desktop\src\LeikwanBridge.App\LeikwanBridge.App.csproj' -c Release -r win-x64 -p:PublishSingleFile=false -p:SelfContained=true -p:IncludeNativeLibrariesForSelfExtract=true -o artifacts\windows\publish --nologo 2>&1 | Select-Object -Last 5
'publish exit=' + $LASTEXITCODE
'### setup builder'
& scripts\build-windows-client-setup.ps1 2>&1 | Select-Object -Last 12
'### result'
Get-Item artifacts\windows\leikwan-bridge-setup.exe | Select-Object Length,LastWriteTime | Format-List
(Get-FileHash artifacts\windows\leikwan-bridge-setup.exe -Algorithm SHA256).Hash.ToLower()
