[CmdletBinding()]
param(
    [string]$AppPublishDir = "artifacts/windows/publish-native-outbound-20260901",
    [string]$OutputSetup = "artifacts/windows/native-outbound-test-20260901-win.exe",
    [string]$StageDir = "artifacts/windows/_native_setup_stage-20260901"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Require-File([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "required file missing: $Path"
    }
}

function Copy-Required([string]$Source, [string]$Destination) {
    Require-File $Source
    New-Item -ItemType Directory -Path (Split-Path -Parent $Destination) -Force | Out-Null
    Copy-Item -LiteralPath $Source -Destination $Destination -Force
}

$appExe = Join-Path $AppPublishDir "LeikwanBridge.App.exe"
Require-File $appExe
$appBuildDir = Join-Path (Split-Path -Parent $AppPublishDir) "..\..\LeikwanBridge.Desktop\src\LeikwanBridge.App\bin\Release\net8.0-windows\win-x64"
$appBuildDir = [System.IO.Path]::GetFullPath($appBuildDir)
Require-File (Join-Path $appBuildDir "LeikwanBridge.App.deps.json")
Require-File (Join-Path $appBuildDir "LeikwanBridge.App.runtimeconfig.json")

if (Test-Path -LiteralPath $StageDir) {
    Remove-Item -LiteralPath $StageDir -Recurse -Force
}
$stageFull = [System.IO.Path]::GetFullPath($StageDir)
$appStage = Join-Path $stageFull "app"
$binStage = Join-Path $appStage "bin"
New-Item -ItemType Directory -Path $binStage -Force | Out-Null

# Keep the apphost under its user-facing name and provide matching sidecars.
Copy-Required $appExe (Join-Path $appStage "leikwan-client-win.exe")
Copy-Required (Join-Path $appBuildDir "LeikwanBridge.App.deps.json") (Join-Path $appStage "leikwan-client-win.deps.json")
Copy-Required (Join-Path $appBuildDir "LeikwanBridge.App.runtimeconfig.json") (Join-Path $appStage "leikwan-client-win.runtimeconfig.json")

# Include the managed assemblies as a fallback for hosts that inspect the app
# directory before launching the self-contained apphost.
foreach ($name in @(
    "LeikwanBridge.App.dll",
    "LeikwanBridge.Core.dll",
    "CommunityToolkit.Mvvm.dll",
    "Serilog.dll",
    "Serilog.Sinks.File.dll"
)) {
    $source = Join-Path $appBuildDir $name
    if (Test-Path -LiteralPath $source -PathType Leaf) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $appStage $name) -Force
    }
}

$runtimeFiles = [ordered]@{
    "wstunnel.exe" = "artifacts/wstunnel/wstunnel_windows_amd64.exe"
    "leikwan-bond.exe" = "artifacts/wstunnel/leikwan-bond_windows_amd64.exe"
    "leikwan-ingress.exe" = "artifacts/wstunnel/leikwan-ingress_windows_amd64.exe"
    "leikwan-uot-bond.exe" = "artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe"
    "leikwan-mptcp-relay.exe" = "artifacts/wstunnel/leikwan-mptcp-relay_windows_amd64.exe"
    "ultra-client.exe" = "artifacts/ultra/ultra-client_windows_amd64.exe"
    "sing-box.exe" = "artifacts/windows/proxy-runtime/sing-box.exe"
    "CN.conf" = "CN.conf"
    "Redirector.bin" = "artifacts/windows/proxy-runtime/Redirector.bin"
    "netfilter2.sys" = "artifacts/windows/proxy-runtime/netfilter2.sys"
    "nfapi.dll" = "artifacts/windows/proxy-runtime/nfapi.dll"
}
foreach ($entry in $runtimeFiles.GetEnumerator()) {
    Copy-Required $entry.Value (Join-Path $binStage $entry.Key)
}

Copy-Required "scripts/windows-client-install-helper.ps1" (Join-Path $stageFull "windows-client-install-helper.ps1")
$version = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($appExe).FileVersion

$installCmd = @"
@echo off
setlocal
set "STAGE=%~dp0"
set "INSTALL_DIR=%LOCALAPPDATA%\Programs\LeiKwan Bridge"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%STAGE%windows-client-install-helper.ps1" -Mode Install -InstallDir "%INSTALL_DIR%" -StageDir "%STAGE%" -InstallMode just-for-me -DesktopShortcut 0 -DisplayName "LeiKwan Bridge" -AppVersion "$version" -UpdateRequested 0
exit /b %errorlevel%
"@
[System.IO.File]::WriteAllText((Join-Path $stageFull "install.cmd"), $installCmd, [System.Text.Encoding]::ASCII)

$sourceFiles = New-Object System.Collections.Generic.List[string]
$sourceFiles.Add("install.cmd")
$sourceFiles.Add("windows-client-install-helper.ps1")
$stageUri = New-Object System.Uri(($stageFull.TrimEnd([char[]]@('\', '/')) + [System.IO.Path]::DirectorySeparatorChar))
foreach ($file in Get-ChildItem -LiteralPath $appStage -File -Recurse) {
    $relative = [System.Uri]::UnescapeDataString(
        $stageUri.MakeRelativeUri((New-Object System.Uri($file.FullName))).ToString()
    ).Replace('/', '\')
    $sourceFiles.Add($relative)
}

$outputFull = [System.IO.Path]::GetFullPath($OutputSetup)
New-Item -ItemType Directory -Path (Split-Path -Parent $outputFull) -Force | Out-Null
if (Test-Path -LiteralPath $outputFull) {
    Remove-Item -LiteralPath $outputFull -Force
}

$sed = New-Object System.Collections.Generic.List[string]
$sed.Add('[Version]')
$sed.Add('Class=IEXPRESS')
$sed.Add('SEDVersion=3')
$sed.Add('[Options]')
$sed.Add('PackagePurpose=InstallApp')
$sed.Add('ShowInstallProgramWindow=1')
$sed.Add('HideExtractAnimation=0')
$sed.Add('UseLongFileName=1')
$sed.Add('InsideCompressed=1')
$sed.Add('CAB_FixedSize=0')
$sed.Add('CAB_ResvCodeSigning=0')
$sed.Add('RebootMode=I')
$sed.Add('InstallPrompt=')
$sed.Add('DisplayLicense=')
$sed.Add('FinishMessage=LeiKwan Bridge installation complete.')
$sed.Add("TargetName=$outputFull")
$sed.Add('FriendlyName=LeiKwan Bridge native outbound test')
$sed.Add('AppLaunched=install.cmd')
$sed.Add('PostInstallCmd=<None>')
$sed.Add('AdminQuietInstCmd=')
$sed.Add('UserQuietInstCmd=')
$sed.Add('SourceFiles=SourceFiles')
$sed.Add('[SourceFiles]')
$sed.Add("SourceFiles0=$stageFull")
$sed.Add('[SourceFiles0]')
for ($index = 0; $index -lt $sourceFiles.Count; $index++) {
    $sed.Add("%FILE$index%=$($sourceFiles[$index])")
}
$sed.Add('[Strings]')
for ($index = 0; $index -lt $sourceFiles.Count; $index++) {
    $sed.Add("FILE$index=$($sourceFiles[$index])")
}
$sedPath = Join-Path $stageFull "package.sed"
[System.IO.File]::WriteAllText($sedPath, ($sed -join "`r`n"), [System.Text.Encoding]::ASCII)

$iexpress = Join-Path $env:WINDIR "System32\iexpress.exe"
Require-File $iexpress
$process = Start-Process -FilePath $iexpress -ArgumentList @('/N', $sedPath) -Wait -PassThru -NoNewWindow
if ($process.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $outputFull -PathType Leaf)) {
    throw "IExpress failed with exit code $($process.ExitCode)"
}

$info = Get-Item -LiteralPath $outputFull
[pscustomobject]@{
    status = "ok"
    artifact = $outputFull
    version = $version
    size_bytes = $info.Length
    sha256 = (Get-FileHash -LiteralPath $outputFull -Algorithm SHA256).Hash.ToLowerInvariant()
    update_manifest_touched = $false
    update_push_touched = $false
} | ConvertTo-Json -Compress
