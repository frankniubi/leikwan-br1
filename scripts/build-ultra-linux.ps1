[CmdletBinding()]
param(
    [string]$Version = "2026.08.27.1",
    [string]$OutputPath = "",
    # amd64 (default) or arm64; the artifact name follows the arch.
    [ValidateSet("amd64", "arm64")]
    [string]$Arch = "amd64",
    # Optional override for the stamped build identity (matches build-ultra-macos.ps1).
    [string]$ShortSha = "",
    [switch]$SkipReproducibilityCheck
)

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\ultra-client"
# Same fingerprint convention as build-ultra-macos.ps1: <version>.<shortsha>.
$shortSha = $ShortSha
if ([string]::IsNullOrWhiteSpace($shortSha)) {
    # $LASTEXITCODE is only meaningful immediately after git ran: an explicit
    # -ShortSha used to be clobbered to "unknown" because a fresh PowerShell
    # session reports $null there, which is -ne 0. The stamped fingerprint then
    # never matched the release manifest, so the panel's attestation check
    # could not recognise a released Linux/Windows runtime at all.
    $shortSha = git -C $repoRoot rev-parse --short HEAD
    if ($LASTEXITCODE -ne 0) { $shortSha = "" }
}
if ([string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = "unknown" }
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

# Do not add garble's tiny mode: the 2026.10.02.1 runtime built with it (v0.17.0,
# Go 1.26) died under sustained external-UoT UDP load (exit 2, once SIGSEGV
# with the instruction pointer in a data page) every 15-90 s; the same source
# built with -literals alone ran clean. Tiny mode also hides every panic message.
function Invoke-GarbleBuild([string]$Destination) {
    & $garblePath -literals "-seed=$seed" build -buildmode=pie -trimpath -buildvcs=false "-ldflags=-s -w -buildid= -X main.runtimeBuildVersion=$FullVersion" -o $Destination .
    if ($LASTEXITCODE -ne 0) {
        throw "garble build failed"
    }
}

# Obfuscation guard. A plain `go build -ldflags '-s -w'` strips the symbol table
# but NOT the pclntab, so every tools/ultra-client function name (main.parseTarget,
# main.credentialChecksum, ...) and -literals-eligible string (leikwan-ultra-policy-v2)
# still ships in cleartext. garble renames those to random identifiers and encrypts
# the literals, so their absence is a reliable proof the binary really went through
# garble rather than a silent fall-through to `go build`. The -X-injected
# runtimeBuildVersion is intentionally NOT in this list: garble preserves it so the
# panel attestation (ultra_runtime_fingerprint / self_sha256) keeps working.
$script:ObfuscationMarkers = @(
    "leikwan-ultra-policy-v2",
    "parseTarget",
    "credentialChecksum",
    "runtimeAttestation",
    "copyInbound",
    "selfExecutableHash",
    "ParseNativeRoutePolicy"
)
function Assert-RuntimeObfuscated([string]$Path) {
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $ascii = [System.Text.Encoding]::ASCII.GetString($bytes)
    $leaks = foreach ($marker in $script:ObfuscationMarkers) {
        if ($ascii.Contains($marker)) { $marker }
    }
    if ($leaks) {
        throw "runtime still contains unobfuscated symbols ($($leaks -join ', ')); garble did not run - refusing to ship a plain go build"
    }
}

try {
    $env:GOOS = "linux"
    $env:GOARCH = $Arch
    $env:CGO_ENABLED = "0"
    Push-Location $sourceRoot
    try {
        Invoke-GarbleBuild $candidatePath
        Assert-RuntimeObfuscated $candidatePath
        if (-not $SkipReproducibilityCheck) {
            Invoke-GarbleBuild $reproPath
            Assert-RuntimeObfuscated $reproPath
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
