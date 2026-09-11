[CmdletBinding()]
param(
    [string]$Version = "2026.09.11.4",
    [string]$ShortSha = "",
    [string]$OutputRoot = "",
    [ValidateSet("arm64", "amd64")]
    [string]$Architectures = "arm64,amd64",
    [switch]$SkipReproducibilityCheck
)

# Build the darwin ultra-client runtime with the exact 2026.09.11.4 flag set
# used for the linux/windows runtimes (garble v0.17.0 -literals -tiny,
# CGO_ENABLED=0, -buildmode=pie, same-origin seed derivation) plus the
# -X main.runtimeBuildVersion=<version>.<shortsha> injection recorded in
# docs/reports/2026-09-11-ultra-runtime-2026.09.11.3-release.md.

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\ultra-client"
if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path $repoRoot "artifacts\ultra"
}
$OutputRoot = [System.IO.Path]::GetFullPath($OutputRoot)
if ([string]::IsNullOrWhiteSpace($ShortSha)) {
    $ShortSha = git -C $repoRoot rev-parse --short HEAD
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($ShortSha)) {
        throw "unable to resolve source short sha"
    }
}
$FullVersion = "$Version.$ShortSha"
$garbleVersion = "v0.17.0"
$toolDir = Join-Path ([System.IO.Path]::GetTempPath()) "leikwan-garble-$garbleVersion"
$garblePath = Join-Path $toolDir "garble.exe"

New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
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
        if ($LASTEXITCODE -ne 0) { throw "failed to install garble $garbleVersion" }
    } finally {
        if ($null -eq $previousGoBin) { Remove-Item Env:GOBIN -ErrorAction SilentlyContinue } else { $env:GOBIN = $previousGoBin }
    }
}

# Same-origin seed derivation as build-ultra-linux.ps1 / build-ultra-android.ps1:
# sha256(Version + source file hash manifest) -> first 8 bytes -> base64.
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

function Invoke-GarbleBuild([string]$Destination) {
    & $garblePath -literals -tiny "-seed=$seed" build -buildmode=pie -trimpath -buildvcs=false "-ldflags=-s -w -buildid= -X main.runtimeBuildVersion=$FullVersion" -o $Destination .
    if ($LASTEXITCODE -ne 0) {
        throw "garble darwin build failed"
    }
}

function Assert-MachO([string]$Path, [int]$ExpectedCpu) {
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -lt 8) { throw "runtime too small to be Mach-O" }
    # Mach-O 64-bit little-endian magic 0xFEEDFACF (bytes CF FA ED FE)
    if ($bytes[0] -ne 0xCF -or $bytes[1] -ne 0xFA -or $bytes[2] -ne 0xED -or $bytes[3] -ne 0xFE) {
        throw "runtime missing Mach-O 64 magic"
    }
    $cpu = [BitConverter]::ToUInt32($bytes, 4)
    $expectedCpuUint = [uint32]$ExpectedCpu
    if ($cpu -ne $expectedCpuUint) { throw "runtime CPU type mismatch (got $cpu, want $expectedCpuUint)" }
}

$results = @()
foreach ($architecture in $Architectures.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ }) {
    $archConfig = switch ($architecture) {
        "arm64" { [pscustomobject]@{ GoArch = "arm64"; Cpu = 0x100000c; ArtifactName = "ultra-client_macos_arm64" } }
        "amd64" { [pscustomobject]@{ GoArch = "amd64"; Cpu = 0x1000007; ArtifactName = "ultra-client_macos_amd64" } }
        default { throw "unsupported darwin architecture: $architecture" }
    }
    $outputPath = Join-Path $OutputRoot $archConfig.ArtifactName
    $candidatePath = Join-Path $OutputRoot (".ultra-client-macos-{0}-{1}.new" -f $architecture, $PID)
    $reproPath = Join-Path $OutputRoot (".ultra-client-macos-{0}-{1}.repro" -f $architecture, $PID)

    try {
        $env:GOOS = "darwin"
        $env:GOARCH = $archConfig.GoArch
        $env:CGO_ENABLED = "0"
        Push-Location $sourceRoot
        try {
            Invoke-GarbleBuild $candidatePath
            Assert-MachO $candidatePath $archConfig.Cpu
            if (-not $SkipReproducibilityCheck) {
                Invoke-GarbleBuild $reproPath
                Assert-MachO $reproPath $archConfig.Cpu
                $candidateHash = (Get-FileHash -LiteralPath $candidatePath -Algorithm SHA256).Hash
                $reproHash = (Get-FileHash -LiteralPath $reproPath -Algorithm SHA256).Hash
                if ($candidateHash -ne $reproHash) {
                    throw "garble reproducibility check failed for $architecture"
                }
            }
        } finally {
            Pop-Location
        }

        Move-Item -LiteralPath $candidatePath -Destination $outputPath -Force
        Assert-MachO $outputPath $archConfig.Cpu
        $artifact = Get-Item -LiteralPath $outputPath
        $results += [pscustomobject]@{
            artifact = $archConfig.ArtifactName
            version = $FullVersion
            sha256 = (Get-FileHash -LiteralPath $outputPath -Algorithm SHA256).Hash.ToLowerInvariant()
            size_bytes = $artifact.Length
            go_version = (& go version)
            obfuscator = "garble-$garbleVersion"
            seed = $seed
            reproducible = -not $SkipReproducibilityCheck
        }
    } finally {
        foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
            $oldValue = $previousEnvironment[$name]
            if ($null -eq $oldValue) { [Environment]::SetEnvironmentVariable($name, $null, "Process") } else { [Environment]::SetEnvironmentVariable($name, $oldValue, "Process") }
        }
        Remove-Item -LiteralPath $candidatePath, $reproPath -Force -ErrorAction SilentlyContinue
    }
}

$results | ConvertTo-Json -Compress | Write-Output
