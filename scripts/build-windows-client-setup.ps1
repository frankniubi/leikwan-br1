param(
    [string]$AppPublishDir = "artifacts/windows/publish",
    [string]$BondExe = "wstunnel-main/target/release/leikwan-bond.exe",
    [string]$IngressExe = "wstunnel-main/target/release/leikwan-ingress.exe",
    [string]$UotBondExe = "wstunnel-main/target/release/leikwan-uot-bond.exe",
    [string]$WstunnelExe = "wstunnel.exe",
    [string]$OutputSetup = "artifacts/windows/leikwan-bridge-setup.exe",
    [string]$StageDir = "artifacts/windows/_setup_stage"
)

$ErrorActionPreference = "Stop"

function Require-File {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "required file missing: $Path"
    }
}

$appExe = Join-Path $AppPublishDir "LeikwanBridge.App.exe"
Require-File $appExe
Require-File $BondExe
Require-File $IngressExe
Require-File $UotBondExe
Require-File $WstunnelExe

if (Test-Path -LiteralPath $StageDir) {
    Remove-Item -LiteralPath $StageDir -Recurse -Force
}

$payloadDir = Join-Path $StageDir "payload"
$binDir = Join-Path $payloadDir "bin"
New-Item -ItemType Directory -Path $binDir -Force | Out-Null

Copy-Item -LiteralPath $appExe -Destination (Join-Path $payloadDir "leikwan-client-win.exe") -Force
Copy-Item -LiteralPath $BondExe -Destination (Join-Path $binDir "leikwan-bond.exe") -Force
Copy-Item -LiteralPath $IngressExe -Destination (Join-Path $binDir "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath $UotBondExe -Destination (Join-Path $binDir "leikwan-uot-bond.exe") -Force
Copy-Item -LiteralPath $WstunnelExe -Destination (Join-Path $binDir "wstunnel.exe") -Force

$installScript = @'
$ErrorActionPreference = "Stop"

$installDir = Join-Path $env:LOCALAPPDATA "LeiKwan Bridge"
$payloadDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$targetBin = Join-Path $installDir "bin"
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDir = Join-Path $installDir ("backup-" + $timestamp)

function Resolve-PayloadFile {
    param(
        [string]$Name,
        [string[]]$Candidates
    )

    foreach ($candidate in $Candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    throw "installer payload missing: $Name"
}

function Ensure-DesktopShortcut {
    param(
        [string]$TargetPath,
        [string]$WorkingDirectory
    )

    $desktopDir = $env:LEIKWAN_DESKTOP_DIR
    if ([string]::IsNullOrWhiteSpace($desktopDir)) {
        $desktopDir = [Environment]::GetFolderPath([System.Environment+SpecialFolder]::DesktopDirectory)
    }

    if ([string]::IsNullOrWhiteSpace($desktopDir)) {
        Write-Warning "desktop directory unavailable; skipping shortcut creation"
        return
    }

    New-Item -ItemType Directory -Path $desktopDir -Force | Out-Null
    $shortcutPath = Join-Path $desktopDir "LeiKwan Bridge.lnk"
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = $TargetPath
    $shortcut.WorkingDirectory = $WorkingDirectory
    $shortcut.IconLocation = "$TargetPath,0"
    $shortcut.Description = "LeiKwan Bridge"
    $shortcut.Save()
}

$sourceAppExe = Resolve-PayloadFile -Name "leikwan-client-win.exe" -Candidates @(
    (Join-Path $payloadDir "app\leikwan-client-win.exe"),
    (Join-Path $payloadDir "leikwan-client-win.exe")
)
$sourceWstunnel = Resolve-PayloadFile -Name "wstunnel.exe" -Candidates @(
    (Join-Path $payloadDir "app\bin\wstunnel.exe"),
    (Join-Path $payloadDir "bin\wstunnel.exe"),
    (Join-Path $payloadDir "wstunnel.exe")
)
$sourceBond = Resolve-PayloadFile -Name "leikwan-bond.exe" -Candidates @(
    (Join-Path $payloadDir "app\bin\leikwan-bond.exe"),
    (Join-Path $payloadDir "bin\leikwan-bond.exe"),
    (Join-Path $payloadDir "leikwan-bond.exe")
)
$sourceIngress = Resolve-PayloadFile -Name "leikwan-ingress.exe" -Candidates @(
    (Join-Path $payloadDir "app\bin\leikwan-ingress.exe"),
    (Join-Path $payloadDir "bin\leikwan-ingress.exe"),
    (Join-Path $payloadDir "leikwan-ingress.exe")
)
$sourceUotBond = Resolve-PayloadFile -Name "leikwan-uot-bond.exe" -Candidates @(
    (Join-Path $payloadDir "app\bin\leikwan-uot-bond.exe"),
    (Join-Path $payloadDir "bin\leikwan-uot-bond.exe"),
    (Join-Path $payloadDir "leikwan-uot-bond.exe")
)
$appSupportFiles = @(
    "CommunityToolkit.Mvvm.dll",
    "LeikwanBridge.App.deps.json",
    "LeikwanBridge.App.dll",
    "LeikwanBridge.App.pdb",
    "LeikwanBridge.App.runtimeconfig.json",
    "LeikwanBridge.Core.dll",
    "LeikwanBridge.Core.pdb",
    "Serilog.dll",
    "Serilog.Sinks.File.dll"
)

New-Item -ItemType Directory -Path $installDir -Force | Out-Null

Get-CimInstance Win32_Process | Where-Object {
    $_.ExecutablePath -and $_.ExecutablePath.StartsWith($installDir, [System.StringComparison]::OrdinalIgnoreCase)
} | ForEach-Object {
    try {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
    } catch {
        Write-Warning "failed to stop process $($_.Name) [$($_.ProcessId)]: $($_.Exception.Message)"
    }
}
Start-Sleep -Milliseconds 800

$backupTargets = @(
    "leikwan-client-win.exe",
    "CommunityToolkit.Mvvm.dll",
    "LeikwanBridge.App.deps.json",
    "LeikwanBridge.App.dll",
    "LeikwanBridge.App.pdb",
    "LeikwanBridge.App.runtimeconfig.json",
    "LeikwanBridge.Core.dll",
    "LeikwanBridge.Core.pdb",
    "Serilog.dll",
    "Serilog.Sinks.File.dll",
    "wstunnel.exe",
    "leikwan-bond.exe",
    "leikwan-ingress.exe",
    "leikwan-uot-bond.exe"
)

$existing = @()
foreach ($name in $backupTargets) {
    $candidate = Join-Path $installDir $name
    if (Test-Path -LiteralPath $candidate) {
        $existing += $candidate
    }
}

if (Test-Path -LiteralPath $targetBin) {
    $existing += $targetBin
}

if ($existing.Count -gt 0) {
    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
    foreach ($item in $existing | Select-Object -Unique) {
        if ((Get-Item $item).PSIsContainer) {
            Copy-Item -LiteralPath $item -Destination (Join-Path $backupDir (Split-Path $item -Leaf)) -Recurse -Force
        } else {
            Copy-Item -LiteralPath $item -Destination (Join-Path $backupDir (Split-Path $item -Leaf)) -Force
        }
    }
}

Get-Process -Name "leikwan-client-win" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

New-Item -ItemType Directory -Path $targetBin -Force | Out-Null

foreach ($supportFile in $appSupportFiles) {
    $sourceSupport = Resolve-PayloadFile -Name $supportFile -Candidates @(
        (Join-Path $payloadDir ("app\" + $supportFile)),
        (Join-Path $payloadDir $supportFile)
    )
    Copy-Item -LiteralPath $sourceSupport -Destination (Join-Path $installDir $supportFile) -Force
}
Copy-Item -LiteralPath $sourceAppExe -Destination (Join-Path $installDir "leikwan-client-win.exe") -Force
Copy-Item -LiteralPath $sourceWstunnel -Destination (Join-Path $targetBin "wstunnel.exe") -Force
Copy-Item -LiteralPath $sourceBond -Destination (Join-Path $targetBin "leikwan-bond.exe") -Force
Copy-Item -LiteralPath $sourceIngress -Destination (Join-Path $targetBin "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath $sourceUotBond -Destination (Join-Path $targetBin "leikwan-uot-bond.exe") -Force

# Keep root-level copies for backward compatibility with older local layouts.
Copy-Item -LiteralPath $sourceWstunnel -Destination (Join-Path $installDir "wstunnel.exe") -Force
Copy-Item -LiteralPath $sourceBond -Destination (Join-Path $installDir "leikwan-bond.exe") -Force
Copy-Item -LiteralPath $sourceIngress -Destination (Join-Path $installDir "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath $sourceUotBond -Destination (Join-Path $installDir "leikwan-uot-bond.exe") -Force

Ensure-DesktopShortcut -TargetPath (Join-Path $installDir "leikwan-client-win.exe") -WorkingDirectory $installDir

$manifest = [ordered]@{
    installed_at = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    source = "leikwan-bridge-setup.exe"
    app_sha256 = (Get-FileHash (Join-Path $installDir "leikwan-client-win.exe") -Algorithm SHA256).Hash
    bond_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-bond.exe") -Algorithm SHA256).Hash
    ingress_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-ingress.exe") -Algorithm SHA256).Hash
    uot_bond_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-uot-bond.exe") -Algorithm SHA256).Hash
    wstunnel_sha256 = (Get-FileHash (Join-Path $targetBin "wstunnel.exe") -Algorithm SHA256).Hash
    backup_dir = if (Test-Path -LiteralPath $backupDir) { $backupDir } else { "" }
}
$manifestPath = Join-Path $installDir "install-manifest.json"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText(
    $manifestPath,
    ($manifest | ConvertTo-Json -Depth 4),
    $utf8NoBom
)

Write-Host "Installed LeiKwan Bridge to $installDir"
if (Test-Path -LiteralPath $backupDir) {
    Write-Host "Backup saved to $backupDir"
}
'@

$installPs1 = Join-Path $StageDir "install.ps1"
Set-Content -LiteralPath $installPs1 -Value $installScript -Encoding UTF8

$launcherCmd = @'
@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
exit /b %errorlevel%
'@
$installCmd = Join-Path $StageDir "install.cmd"
Set-Content -LiteralPath $installCmd -Value $launcherCmd -Encoding ASCII

$appStageDir = Join-Path $StageDir "app"
$appBinStageDir = Join-Path $appStageDir "bin"
New-Item -ItemType Directory -Path $appBinStageDir -Force | Out-Null

Copy-Item -LiteralPath $appExe -Destination (Join-Path $appStageDir "leikwan-client-win.exe") -Force
Get-ChildItem -LiteralPath $AppPublishDir -File | Where-Object { $_.Name -ne "LeikwanBridge.App.exe" } | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $appStageDir $_.Name) -Force
}
Copy-Item -LiteralPath (Join-Path $binDir "wstunnel.exe") -Destination (Join-Path $appBinStageDir "wstunnel.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-bond.exe") -Destination (Join-Path $appBinStageDir "leikwan-bond.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-ingress.exe") -Destination (Join-Path $appBinStageDir "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-uot-bond.exe") -Destination (Join-Path $appBinStageDir "leikwan-uot-bond.exe") -Force

$sedPath = Join-Path $StageDir "package.sed"
$packageTitle = "LeiKwan Bridge Setup"
$outputName = Split-Path -Leaf $OutputSetup
$sourceFiles = @(
    "install.cmd",
    "install.ps1"
)
$sourceFiles += Get-ChildItem -LiteralPath $appStageDir -File | ForEach-Object { "app\" + $_.Name }
$sourceFiles += Get-ChildItem -LiteralPath $appBinStageDir -File | ForEach-Object { "app\bin\" + $_.Name }

$sedContent = @"
[Version]
Class=IEXPRESS
SEDVersion=3
[Options]
PackagePurpose=InstallApp
ShowInstallProgramWindow=1
HideExtractAnimation=0
UseLongFileName=1
InsideCompressed=0
CAB_FixedSize=0
CAB_ResvCodeSigning=0
RebootMode=I
InstallPrompt=
DisplayLicense=
FinishMessage=LeiKwan Bridge installation complete.
TargetName=$([System.IO.Path]::GetFullPath($OutputSetup))
FriendlyName=$packageTitle
AppLaunched=install.cmd
PostInstallCmd=<None>
AdminQuietInstCmd=
UserQuietInstCmd=
SourceFiles=SourceFiles
[SourceFiles]
SourceFiles0=$([System.IO.Path]::GetFullPath($StageDir))
[SourceFiles0]
"@

for ($i = 0; $i -lt $sourceFiles.Count; $i++) {
    $sedContent += "`r`n%FILE$i%=" + $sourceFiles[$i]
}

$sedContent += "`r`n[Strings]"
for ($i = 0; $i -lt $sourceFiles.Count; $i++) {
    $sedContent += "`r`nFILE$i=" + $sourceFiles[$i]
}

Set-Content -LiteralPath $sedPath -Value $sedContent -Encoding ASCII

if (Test-Path -LiteralPath $OutputSetup) {
    Remove-Item -LiteralPath $OutputSetup -Force
}

Start-Process -FilePath "$env:WINDIR\System32\iexpress.exe" -ArgumentList "/N", $sedPath -Wait -NoNewWindow

if (-not (Test-Path -LiteralPath $OutputSetup)) {
    throw "failed to create setup file: $OutputSetup"
}

$hash = (Get-FileHash -LiteralPath $OutputSetup -Algorithm SHA256).Hash
Write-Host "Created $OutputSetup"
Write-Host "SHA256=$hash"
