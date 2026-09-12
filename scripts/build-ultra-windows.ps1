[CmdletBinding()]
param(
    [string]$Version = "2026.09.12.2",
    [string]$OutputPath = "",
    # Optional override for the stamped build identity (matches build-ultra-macos.ps1).
    [string]$ShortSha = "",
    # Garble obfuscation is OFF by default on Windows. Windows Defender flags
    # every garble-produced image as Trojan:Win32/Gracing.* and quarantines the
    # installed runtime; the client then dies with "access denied" and the
    # update check reports permanent runtime drift for the update it just
    # installed. Availability wins: the anti-reverse work is carried by the
    # attestation fingerprint plus the signed release manifest. Re-enable only
    # after a Defender false-positive report for the artifact has cleared.
    [switch]$Obfuscate,
    [switch]$SkipReproducibilityCheck,
    [switch]$SkipDefenderScan
)

# Windows runtime build. Mirrors build-ultra-linux.ps1, minus obfuscation by
# default: same reproducibility check and the same -X main.runtimeBuildVersion
# stamp so the panel can match the artifact hash of a running helper against
# the release manifest (see tools/ultra-client/attest.go).
$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\ultra-client"
# Same fingerprint convention as build-ultra-macos.ps1: <version>.<shortsha>.
$shortSha = $ShortSha
if ([string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = git -C $repoRoot rev-parse --short HEAD }
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = "unknown" }
$FullVersion = "$Version.$shortSha"
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot "artifacts\ultra\ultra-client_windows_amd64.exe"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDir = Split-Path -Parent $OutputPath
$garbleVersion = "v0.17.0"
$toolDir = Join-Path ([System.IO.Path]::GetTempPath()) "leikwan-garble-$garbleVersion"
$garblePath = Join-Path $toolDir "garble.exe"
$candidatePath = Join-Path $outputDir (".ultra-client-windows-{0}.new" -f $PID)
$reproPath = Join-Path $outputDir (".ultra-client-windows-{0}.repro" -f $PID)

New-Item -ItemType Directory -Path $outputDir -Force | Out-Null

if ($Obfuscate) {
    New-Item -ItemType Directory -Path $toolDir -Force | Out-Null
    $needsInstall = -not (Test-Path -LiteralPath $garblePath)
    if (-not $needsInstall) {
        $installedVersion = (& $garblePath version 2>$null | Select-Object -First 1)
        $needsInstall = $installedVersion -notmatch [regex]::Escape($garbleVersion)
    }
    if ($needsInstall) {
        $previousGoBin = $env:GOBIN
        try {
            $env:GOBIN = $toolDir
            & go install "mvdan.cc/garble@$garbleVersion"
            if ($LASTEXITCODE -ne 0) {
                throw "failed to install garble $garbleVersion"
            }
        } finally {
            if ($null -eq $previousGoBin) { Remove-Item Env:GOBIN -ErrorAction SilentlyContinue } else { $env:GOBIN = $previousGoBin }
        }
    }
}

$sourceFiles = Get-ChildItem -LiteralPath $sourceRoot -File |
    Where-Object { $_.Extension -eq ".go" -or $_.Name -in @("go.mod", "go.sum") } |
    Sort-Object Name
$sourceParts = foreach ($file in $sourceFiles) {
    "{0}:{1}" -f $file.Name, (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}
$seedMaterial = [System.Text.Encoding]::UTF8.GetBytes($Version + [char]10 + ($sourceParts -join [char]10))
$sha256 = [System.Security.Cryptography.SHA256]::Create()
try {
    $seedDigest = $sha256.ComputeHash($seedMaterial)
} finally {
    $sha256.Dispose()
}
$seedBytes = New-Object byte[] 8
[Array]::Copy($seedDigest, $seedBytes, 8)
$seed = [Convert]::ToBase64String($seedBytes).TrimEnd("=")

$previousEnvironment = @{}
foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, "Process")
}

function Invoke-RuntimeBuild([string]$Destination) {
    $ldflags = "-s -w -buildid= -X main.runtimeBuildVersion=$FullVersion"
    if ($Obfuscate) {
        & $garblePath -literals -tiny "-seed=$seed" build -trimpath -buildvcs=false "-ldflags=$ldflags" -o $Destination .
        if ($LASTEXITCODE -ne 0) {
            throw "garble windows build failed"
        }
        return
    }

    & go build -trimpath -buildvcs=false "-ldflags=$ldflags" -o $Destination .
    if ($LASTEXITCODE -ne 0) {
        throw "windows runtime build failed"
    }
}

function Get-DefenderScanTool {
    $platformRoot = Join-Path $env:ProgramData "Microsoft\Windows Defender\Platform"
    if (-not (Test-Path -LiteralPath $platformRoot)) { return "" }
    $platform = Get-ChildItem -LiteralPath $platformRoot -Directory -ErrorAction SilentlyContinue |
        Sort-Object Name -Descending | Select-Object -First 1
    if ($null -eq $platform) { return "" }
    $tool = Join-Path $platform.FullName "MpCmdRun.exe"
    if (Test-Path -LiteralPath $tool) { return $tool }
    return ""
}

# A runtime that Windows Defender quarantines is worse than an obfuscated one:
# the client cannot launch it at all. Fail the build instead of shipping it.
function Assert-DefenderClean([string]$ArtifactPath) {
    if ($SkipDefenderScan) { return "skipped" }
    $tool = Get-DefenderScanTool
    if ([string]::IsNullOrWhiteSpace($tool)) {
        Write-Warning "MpCmdRun.exe not found; Defender scan skipped"
        return "skipped"
    }
    $output = (& $tool -Scan -ScanType 3 -File $ArtifactPath -DisableRemediation 2>&1) -join [char]10
    $exitCode = $LASTEXITCODE
    if ($exitCode -eq 0) { return "clean" }
    $threat = (($output -split [char]10) | Where-Object { $_ -match "Threat\s*:" } | Select-Object -First 1)
    throw "Windows Defender rejected the runtime artifact (exit $exitCode). $($threat.Trim()) Build with -Obfuscate:$false or fix the detection before shipping."
}

try {
    $env:GOOS = "windows"
    $env:GOARCH = "amd64"
    $env:CGO_ENABLED = "0"
    Push-Location $sourceRoot
    try {
        Invoke-RuntimeBuild $candidatePath
        if (-not $SkipReproducibilityCheck) {
            Invoke-RuntimeBuild $reproPath
            $candidateHash = (Get-FileHash -LiteralPath $candidatePath -Algorithm SHA256).Hash
            $reproHash = (Get-FileHash -LiteralPath $reproPath -Algorithm SHA256).Hash
            if ($candidateHash -ne $reproHash) {
                throw "runtime reproducibility check failed"
            }
        }
    } finally {
        Pop-Location
    }

    $defenderState = Assert-DefenderClean -ArtifactPath $candidatePath

    Move-Item -LiteralPath $candidatePath -Destination $OutputPath -Force
    $artifact = Get-Item -LiteralPath $OutputPath
    [pscustomobject]@{
        version = $Version
        sha256 = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
        size_bytes = $artifact.Length
        go_version = (& go version)
        obfuscator = if ($Obfuscate) { "garble-$garbleVersion" } else { "none" }
        defender_scan = $defenderState
        reproducible = -not $SkipReproducibilityCheck
    } | ConvertTo-Json -Compress
} finally {
    foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
        $oldValue = $previousEnvironment[$name]
        if ($null -eq $oldValue) { [Environment]::SetEnvironmentVariable($name, $null, "Process") } else { [Environment]::SetEnvironmentVariable($name, $oldValue, "Process") }
    }
    Remove-Item -LiteralPath $candidatePath, $reproPath -Force -ErrorAction SilentlyContinue
}