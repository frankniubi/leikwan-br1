param(
    [string]$AppPublishDir = "artifacts/windows/publish",
    [string]$BondExe = "wstunnel-main/target/release/leikwan-bond.exe",
    [string]$IngressExe = "wstunnel-main/target/release/leikwan-ingress.exe",
    [string]$UotBondExe = "wstunnel-main/target/release/leikwan-uot-bond.exe",
    [string]$MptcpRelayExe = "wstunnel-main/target/x86_64-pc-windows-msvc/release/leikwan-mptcp-relay.exe",
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

function Resolve-InputFile {
    param(
        [string]$ConfiguredPath,
        [bool]$WasExplicitlyProvided,
        [string[]]$Fallbacks
    )

    if ($WasExplicitlyProvided) {
        Require-File $ConfiguredPath
        return $ConfiguredPath
    }

    if (-not [string]::IsNullOrWhiteSpace($ConfiguredPath) -and (Test-Path -LiteralPath $ConfiguredPath)) {
        return $ConfiguredPath
    }

    foreach ($candidate in $Fallbacks) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    throw "required file missing: $ConfiguredPath"
}

$appExe = Join-Path $AppPublishDir "LeikwanBridge.App.exe"
$appBuildDir = Join-Path (Split-Path -Parent $AppPublishDir) "..\..\LeikwanBridge.Desktop\src\LeikwanBridge.App\bin\Release\net8.0-windows\win-x64"
$appBuildDir = [System.IO.Path]::GetFullPath($appBuildDir)
Require-File $appExe
if (-not (Test-Path -LiteralPath $appBuildDir)) {
    throw "required app build directory missing: $appBuildDir"
}
$BondExe = Resolve-InputFile -ConfiguredPath $BondExe -WasExplicitlyProvided $PSBoundParameters.ContainsKey('BondExe') -Fallbacks @(
    "artifacts/wstunnel/leikwan-bond_windows_amd64.exe",
    "artifacts/windows/rebuild_payload/leikwan-bond.exe"
)
$IngressExe = Resolve-InputFile -ConfiguredPath $IngressExe -WasExplicitlyProvided $PSBoundParameters.ContainsKey('IngressExe') -Fallbacks @(
    "artifacts/wstunnel/leikwan-ingress_windows_amd64.exe",
    "artifacts/windows/rebuild_payload/leikwan-ingress.exe"
)
$UotBondExe = Resolve-InputFile -ConfiguredPath $UotBondExe -WasExplicitlyProvided $PSBoundParameters.ContainsKey('UotBondExe') -Fallbacks @(
    "artifacts/windows/rebuild_payload/leikwan-uot-bond.exe"
)
$MptcpRelayExe = Resolve-InputFile -ConfiguredPath $MptcpRelayExe -WasExplicitlyProvided $PSBoundParameters.ContainsKey('MptcpRelayExe') -Fallbacks @(
    "artifacts/wstunnel/leikwan-mptcp-relay_windows_amd64.exe",
    "artifacts/windows/rebuild_payload/leikwan-mptcp-relay.exe"
)
$WstunnelExe = Resolve-InputFile -ConfiguredPath $WstunnelExe -WasExplicitlyProvided $PSBoundParameters.ContainsKey('WstunnelExe') -Fallbacks @(
    "artifacts/wstunnel/wstunnel_windows_amd64.exe",
    "artifacts/windows/rebuild_payload/wstunnel.exe",
    "wstunnel.exe"
)

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
Copy-Item -LiteralPath $MptcpRelayExe -Destination (Join-Path $binDir "leikwan-mptcp-relay.exe") -Force
Copy-Item -LiteralPath $WstunnelExe -Destination (Join-Path $binDir "wstunnel.exe") -Force

$installScript = @'
param(
    [switch]$Elevated,
    [string]$InstallDirOverride,
    [string]$DesktopDirOverride
)

$ErrorActionPreference = "Stop"

$programFilesDir = if (-not [string]::IsNullOrWhiteSpace($env:ProgramW6432)) {
    $env:ProgramW6432
} elseif (-not [string]::IsNullOrWhiteSpace($env:ProgramFiles)) {
    $env:ProgramFiles
} else {
    [Environment]::GetFolderPath([System.Environment+SpecialFolder]::ProgramFiles)
}

$resolvedInstallDir = if (-not [string]::IsNullOrWhiteSpace($InstallDirOverride)) {
    $InstallDirOverride
} elseif (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_RELAUNCH_INSTALL_DIR)) {
    $env:LEIKWAN_RELAUNCH_INSTALL_DIR
} elseif (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_INSTALL_DIR)) {
    $env:LEIKWAN_INSTALL_DIR
} else {
    Join-Path $programFilesDir "LeiKwan Bridge"
}

$resolvedDesktopDir = if (-not [string]::IsNullOrWhiteSpace($DesktopDirOverride)) {
    $DesktopDirOverride
} elseif (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_RELAUNCH_DESKTOP_DIR)) {
    $env:LEIKWAN_RELAUNCH_DESKTOP_DIR
} elseif (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_DESKTOP_DIR)) {
    $env:LEIKWAN_DESKTOP_DIR
} else {
    [Environment]::GetFolderPath([System.Environment+SpecialFolder]::DesktopDirectory)
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-RequiresElevation {
    param([string]$TargetDir)

    $trimChars = [char[]]@([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
    $programFiles = [System.IO.Path]::GetFullPath($programFilesDir).TrimEnd($trimChars)
    $target = [System.IO.Path]::GetFullPath($TargetDir).TrimEnd($trimChars)
    $programFilesWithSeparator = $programFiles + [System.IO.Path]::DirectorySeparatorChar

    return $target.Equals($programFiles, [System.StringComparison]::OrdinalIgnoreCase) -or
        $target.StartsWith($programFilesWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)
}

if (Test-RequiresElevation -TargetDir $resolvedInstallDir -and -not (Test-IsAdministrator)) {
    if ($Elevated) {
        throw "installer relaunched with -Elevated but still lacks administrator rights"
    }

    $env:LEIKWAN_RELAUNCH_INSTALL_DIR = $resolvedInstallDir
    $env:LEIKWAN_RELAUNCH_DESKTOP_DIR = $resolvedDesktopDir

    $psiArgs = @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", $PSCommandPath,
        "-Elevated"
    )

    $process = Start-Process -FilePath "powershell.exe" -Verb RunAs -ArgumentList $psiArgs -Wait -PassThru
    exit $process.ExitCode
}

$installDir = $resolvedInstallDir
$desktopDir = $resolvedDesktopDir
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
        [string]$WorkingDirectory,
        [string]$DesktopDirPath
    )

    if ([string]::IsNullOrWhiteSpace($DesktopDirPath)) {
        Write-Warning "desktop directory unavailable; skipping shortcut creation"
        return
    }

    New-Item -ItemType Directory -Path $DesktopDirPath -Force | Out-Null
    $shortcutPath = Join-Path $DesktopDirPath "LeiKwan Bridge.lnk"
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
$sourceMptcpRelay = Resolve-PayloadFile -Name "leikwan-mptcp-relay.exe" -Candidates @(
    (Join-Path $payloadDir "app\bin\leikwan-mptcp-relay.exe"),
    (Join-Path $payloadDir "bin\leikwan-mptcp-relay.exe"),
    (Join-Path $payloadDir "leikwan-mptcp-relay.exe")
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
    "leikwan-uot-bond.exe",
    "leikwan-mptcp-relay.exe"
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
Copy-Item -LiteralPath $sourceMptcpRelay -Destination (Join-Path $targetBin "leikwan-mptcp-relay.exe") -Force

# Keep root-level copies for backward compatibility with older local layouts.
Copy-Item -LiteralPath $sourceWstunnel -Destination (Join-Path $installDir "wstunnel.exe") -Force
Copy-Item -LiteralPath $sourceBond -Destination (Join-Path $installDir "leikwan-bond.exe") -Force
Copy-Item -LiteralPath $sourceIngress -Destination (Join-Path $installDir "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath $sourceUotBond -Destination (Join-Path $installDir "leikwan-uot-bond.exe") -Force
Copy-Item -LiteralPath $sourceMptcpRelay -Destination (Join-Path $installDir "leikwan-mptcp-relay.exe") -Force

Ensure-DesktopShortcut -TargetPath (Join-Path $installDir "leikwan-client-win.exe") -WorkingDirectory $installDir -DesktopDirPath $desktopDir

$manifest = [ordered]@{
    installed_at = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    source = "leikwan-bridge-setup.exe"
    app_sha256 = (Get-FileHash (Join-Path $installDir "leikwan-client-win.exe") -Algorithm SHA256).Hash
    bond_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-bond.exe") -Algorithm SHA256).Hash
    ingress_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-ingress.exe") -Algorithm SHA256).Hash
    uot_bond_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-uot-bond.exe") -Algorithm SHA256).Hash
    mptcp_relay_sha256 = (Get-FileHash (Join-Path $targetBin "leikwan-mptcp-relay.exe") -Algorithm SHA256).Hash
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
Get-ChildItem -LiteralPath $appBuildDir -File | Where-Object { $_.Name -ne "LeikwanBridge.App.exe" } | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $appStageDir $_.Name) -Force
}
Copy-Item -LiteralPath (Join-Path $binDir "wstunnel.exe") -Destination (Join-Path $appBinStageDir "wstunnel.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-bond.exe") -Destination (Join-Path $appBinStageDir "leikwan-bond.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-ingress.exe") -Destination (Join-Path $appBinStageDir "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-uot-bond.exe") -Destination (Join-Path $appBinStageDir "leikwan-uot-bond.exe") -Force
Copy-Item -LiteralPath (Join-Path $binDir "leikwan-mptcp-relay.exe") -Destination (Join-Path $appBinStageDir "leikwan-mptcp-relay.exe") -Force

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
    $sedContent += "
%FILE$i%=" + $sourceFiles[$i]
}

$sedContent += "
[Strings]"
for ($i = 0; $i -lt $sourceFiles.Count; $i++) {
    $sedContent += "
FILE$i=" + $sourceFiles[$i]
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
