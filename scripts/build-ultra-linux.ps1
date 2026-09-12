[CmdletBinding()]
param(
    [string]$Version = "2026.08.27.1",
    [string]$OutputPath = "",
    # amd64 (default) or arm64; the artifact name follows the arch.
    [ValidateSet("amd64", "arm64")]
    [string]$Arch = "amd64",
    [switch]$SkipReproducibilityCheck
)

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\ultra-client"
# Same fingerprint convention as build-ultra-macos.ps1: <version>.<shortsha>.
$shortSha = git -C $repoRoot rev-parse --short HEAD
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = "unknown" }
$FullVersion = "$Version.$shortSha"
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $archSuffix = if ($Arch -eq "arm64") { "arm64" } else { "amd64" }
    $OutputPath = Join-Path $repoRoot "artifacts\ultra\ultra-client_linux_$archSuffix"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDir = Split-Path -Parent $OutputPath
$garbleVersion = "v0.17.0"
$toolDir = Join-Path ([System.IO.Path]::GetTempPath()) "leikwan-garble-$garbleVersion"
$garblePath = Join-Path $toolDir "garble.exe"
$candidatePath = Join-Path $outputDir (".ultra-client-linux-{0}.new" -f $PID)
$reproPath = Join-Path $outputDir (".ultra-client-linux-{0}.repro" -f $PID)

New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
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

$sourceFiles = Get-ChildItem -LiteralPath $sourceRoot -File |
    Where-Object { $_.Extension -eq ".go" -or $_.Name -in @("go.mod", "go.sum") } |
    Sort-Object Name
$sourceParts = foreach ($file in $sourceFiles) {
    "{0}:{1}" -f $file.Name, (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}
$seedMaterial = [System.Text.Encoding]::UTF8.GetBytes($Version + "`n" + ($sourceParts -join "`n"))
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

function Invoke-GarbleBuild([string]$Destination) {
    & $garblePath -literals -tiny "-seed=$seed" build -buildmode=pie -trimpath -buildvcs=false "-ldflags=-s -w -buildid= -X main.runtimeBuildVersion=$FullVersion" -o $Destination .
    if ($LASTEXITCODE -ne 0) {
        throw "garble build failed"
    }
}

try {
    $env:GOOS = "linux"
    $env:GOARCH = $Arch
    $env:CGO_ENABLED = "0"
    Push-Location $sourceRoot
    try {
        Invoke-GarbleBuild $candidatePath
        if (-not $SkipReproducibilityCheck) {
            Invoke-GarbleBuild $reproPath
            $candidateHash = (Get-FileHash -LiteralPath $candidatePath -Algorithm SHA256).Hash
            $reproHash = (Get-FileHash -LiteralPath $reproPath -Algorithm SHA256).Hash
            if ($candidateHash -ne $reproHash) {
                throw "garble reproducibility check failed"
            }
        }
    } finally {
        Pop-Location
    }

    Move-Item -LiteralPath $candidatePath -Destination $OutputPath -Force
    $artifact = Get-Item -LiteralPath $OutputPath
    [pscustomobject]@{
        version = $Version
        sha256 = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
        size_bytes = $artifact.Length
        go_version = (& go version)
        obfuscator = "garble-$garbleVersion"
        reproducible = -not $SkipReproducibilityCheck
    } | ConvertTo-Json -Compress
} finally {
    foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
        $oldValue = $previousEnvironment[$name]
        if ($null -eq $oldValue) { [Environment]::SetEnvironmentVariable($name, $null, "Process") } else { [Environment]::SetEnvironmentVariable($name, $oldValue, "Process") }
    }
    Remove-Item -LiteralPath $candidatePath, $reproPath -Force -ErrorAction SilentlyContinue
}
