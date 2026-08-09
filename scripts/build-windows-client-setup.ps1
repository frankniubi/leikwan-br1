param(
    [string]$AppPublishDir = "artifacts/windows/publish",
    [string]$BondExe = "wstunnel-main/target/release/leikwan-bond.exe",
    [string]$IngressExe = "wstunnel-main/target/release/leikwan-ingress.exe",
    [string]$UotBondExe = "artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe",
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

function Get-RepoRootCandidates {
    $candidates = New-Object System.Collections.Generic.List[string]
    $current = [System.IO.Path]::GetFullPath((Get-Location).Path)
    $directories = @($current)
    $directories += [System.IO.Directory]::GetParent($current) | ForEach-Object { if ($_) { $_.FullName } }
    $directories += [System.IO.Directory]::GetParent([System.IO.Directory]::GetParent($current).FullName) | ForEach-Object { if ($_) { $_.FullName } }
    $directories += [System.IO.Directory]::GetParent([System.IO.Directory]::GetParent([System.IO.Directory]::GetParent($current).FullName).FullName) | ForEach-Object { if ($_) { $_.FullName } }

    foreach ($candidate in $directories | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -Unique) {
        if (Test-Path -LiteralPath (Join-Path $candidate "LeikwanBridge.Desktop")) {
            $candidates.Add($candidate)
        }
    }

    return $candidates | Select-Object -Unique
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

    $repoRootCandidates = @(Get-RepoRootCandidates)
    $candidatePaths = New-Object System.Collections.Generic.List[string]

    if (-not [string]::IsNullOrWhiteSpace($ConfiguredPath)) {
        $candidatePaths.Add($ConfiguredPath)
    }

    foreach ($fallback in $Fallbacks) {
        if (-not [string]::IsNullOrWhiteSpace($fallback)) {
            $candidatePaths.Add($fallback)
        }
    }

    $expandedCandidates = New-Object System.Collections.Generic.List[string]
    foreach ($candidate in $candidatePaths | Select-Object -Unique) {
        $expandedCandidates.Add($candidate)
        foreach ($repoRoot in $repoRootCandidates) {
            $expandedCandidates.Add((Join-Path $repoRoot $candidate))
        }
    }

    foreach ($candidate in $expandedCandidates | Select-Object -Unique) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and (Test-Path -LiteralPath $candidate)) {
            return [System.IO.Path]::GetFullPath($candidate)
        }
    }

    throw "required file missing: $ConfiguredPath"
}

function Resolve-IsccPath {
    $command = Get-Command ISCC.exe -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    $candidates = @(
        "C:\Program Files (x86)\Inno Setup 6\ISCC.exe",
        "C:\Program Files\Inno Setup 6\ISCC.exe",
        "C:\Program Files (x86)\Inno Setup 5\ISCC.exe",
        "C:\Program Files\Inno Setup 5\ISCC.exe",
        "C:\Tools\Inno Setup 6\ISCC.exe",
        "C:\Tools\Inno Setup\ISCC.exe"
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    return $null
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
    "artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe"
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

$versionsPath = "artifacts/versions.json"
Require-File $versionsPath
$versions = Get-Content -LiteralPath $versionsPath -Raw | ConvertFrom-Json
$uotManifest = $versions.leikwan_uot_bond_windows_amd64
if (-not $uotManifest -or [string]::IsNullOrWhiteSpace([string]$uotManifest.sha256)) {
    throw "missing leikwan_uot_bond_windows_amd64 manifest metadata"
}
$actualUotSha256 = (Get-FileHash -LiteralPath $UotBondExe -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actualUotSha256 -ne ([string]$uotManifest.sha256).ToLowerInvariant()) {
    throw "UoT binary sha256 does not match manifest"
}
$uotBuildInfoRaw = & $UotBondExe --build-info-json
if ($LASTEXITCODE -ne 0) {
    throw "UoT binary build-info probe failed"
}
$uotBuildInfo = $uotBuildInfoRaw | ConvertFrom-Json
if (
    [int]$uotBuildInfo.protocol_version -ne 2 -or
    -not (@($uotBuildInfo.scheduler_modes) -contains "feedback_drr") -or
    -not (@($uotBuildInfo.capabilities) -contains "leg-progress-v1")
) {
    throw "UoT binary lacks feedback_drr/leg-progress-v1"
}

if (Test-Path -LiteralPath $StageDir) {
    Remove-Item -LiteralPath $StageDir -Recurse -Force
}

$appStageDir = Join-Path $StageDir "app"
$appBinStageDir = Join-Path $appStageDir "bin"
New-Item -ItemType Directory -Path $appBinStageDir -Force | Out-Null

$helperScript = Join-Path "scripts" "windows-client-install-helper.ps1"
Require-File $helperScript
Copy-Item -LiteralPath $helperScript -Destination (Join-Path $StageDir "windows-client-install-helper.ps1") -Force

Copy-Item -LiteralPath $appExe -Destination (Join-Path $appStageDir "leikwan-client-win.exe") -Force
Get-ChildItem -LiteralPath $appBuildDir -File | Where-Object { $_.Name -ne "LeikwanBridge.App.exe" } | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $appStageDir $_.Name) -Force
}
Copy-Item -LiteralPath $WstunnelExe -Destination (Join-Path $appBinStageDir "wstunnel.exe") -Force
Copy-Item -LiteralPath $BondExe -Destination (Join-Path $appBinStageDir "leikwan-bond.exe") -Force
Copy-Item -LiteralPath $IngressExe -Destination (Join-Path $appBinStageDir "leikwan-ingress.exe") -Force
Copy-Item -LiteralPath $UotBondExe -Destination (Join-Path $appBinStageDir "leikwan-uot-bond.exe") -Force
Copy-Item -LiteralPath $MptcpRelayExe -Destination (Join-Path $appBinStageDir "leikwan-mptcp-relay.exe") -Force

$innoScript = Join-Path "scripts" "windows-client-setup.iss"
if (-not (Test-Path -LiteralPath $innoScript)) {
    throw "Inno Setup script missing: $innoScript"
}

$iscc = Resolve-IsccPath
if (-not $iscc) {
    throw "Inno Setup compiler not found: ISCC.exe"
}

if (Test-Path -LiteralPath $OutputSetup) {
    Remove-Item -LiteralPath $OutputSetup -Force
}

$outputDir = Split-Path -Parent $OutputSetup
if ([string]::IsNullOrWhiteSpace($outputDir)) {
    throw "output setup directory missing for $OutputSetup"
}

$version = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($appExe).FileVersion
$compileArgs = @(
    "/DAppVersion=$version",
    "/DStageDir=$([System.IO.Path]::GetFullPath($StageDir))",
    "/DOutputDir=$([System.IO.Path]::GetFullPath($outputDir))",
    $innoScript
)

Start-Process -FilePath $iscc -ArgumentList $compileArgs -Wait -NoNewWindow

if (-not (Test-Path -LiteralPath $OutputSetup)) {
    throw "failed to create setup file: $OutputSetup"
}

$hash = (Get-FileHash -LiteralPath $OutputSetup -Algorithm SHA256).Hash
Write-Host "Created $OutputSetup"
Write-Host "SHA256=$hash"
