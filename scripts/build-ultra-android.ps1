[CmdletBinding()]
param(
    [string]$Version = "2026.08.27.1",
    [string]$OutputPath = "",
    [switch]$SkipReproducibilityCheck
)

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\ultra-client"
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot "artifacts\ultra\ultra-client_android_arm64"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDir = Split-Path -Parent $OutputPath
$assetPath = Join-Path $repoRoot "leikwan-android\app\src\main\assets\runtime\arm64-v8a\ultra-client"
$jniPath = Join-Path $repoRoot "leikwan-android\app\src\main\jniLibs\arm64-v8a\libultra_client.so"
$garbleVersion = "v0.17.0"
$toolDir = Join-Path ([System.IO.Path]::GetTempPath()) "leikwan-garble-$garbleVersion"
$garblePath = Join-Path $toolDir "garble.exe"
$candidatePath = Join-Path $outputDir (".ultra-client-android-{0}.new" -f $PID)
$reproPath = Join-Path $outputDir (".ultra-client-android-{0}.repro" -f $PID)

New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
New-Item -ItemType Directory -Path $toolDir -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path -Parent $assetPath) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path -Parent $jniPath) -Force | Out-Null

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
    & $garblePath -literals -tiny "-seed=$seed" build -buildmode=pie -trimpath -buildvcs=false "-ldflags=-s -w -buildid=" -o $Destination .
    if ($LASTEXITCODE -ne 0) {
        throw "garble Android arm64 build failed"
    }
}

function Assert-AndroidArm64Elf([string]$Path) {
    $header = [System.IO.File]::ReadAllBytes($Path)
    if ($header.Length -lt 20 -or $header[0] -ne 0x7f -or $header[1] -ne [byte][char]'E' -or $header[2] -ne [byte][char]'L' -or $header[3] -ne [byte][char]'F') {
        throw "runtime is not an ELF binary"
    }
    if ($header[4] -ne 2 -or $header[5] -ne 1) {
        throw "runtime is not ELF64 little-endian"
    }
    $machine = [BitConverter]::ToUInt16($header, 18)
    if ($machine -ne 183) {
        throw "runtime is not AArch64 (e_machine=$machine)"
    }
}

try {
    $env:GOOS = "android"
    $env:GOARCH = "arm64"
    $env:CGO_ENABLED = "0"
    Push-Location $sourceRoot
    try {
        Invoke-GarbleBuild $candidatePath
        Assert-AndroidArm64Elf $candidatePath
        if (-not $SkipReproducibilityCheck) {
            Invoke-GarbleBuild $reproPath
            Assert-AndroidArm64Elf $reproPath
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
    Assert-AndroidArm64Elf $OutputPath
    Copy-Item -LiteralPath $OutputPath -Destination $assetPath -Force
    Copy-Item -LiteralPath $OutputPath -Destination $jniPath -Force
    $artifactHash = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    foreach ($path in @($assetPath, $jniPath)) {
        Assert-AndroidArm64Elf $path
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $artifactHash) {
            throw "runtime copy hash mismatch: $path"
        }
    }
    $artifact = Get-Item -LiteralPath $OutputPath
    [pscustomobject]@{
        version = $Version
        sha256 = $artifactHash
        size_bytes = $artifact.Length
        go_version = (& go version)
        obfuscator = "garble-$garbleVersion"
        reproducible = -not $SkipReproducibilityCheck
        asset_path = $assetPath
        jni_path = $jniPath
    } | ConvertTo-Json -Compress
} finally {
    foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
        $oldValue = $previousEnvironment[$name]
        if ($null -eq $oldValue) { [Environment]::SetEnvironmentVariable($name, $null, "Process") } else { [Environment]::SetEnvironmentVariable($name, $oldValue, "Process") }
    }
    Remove-Item -LiteralPath $candidatePath, $reproPath -Force -ErrorAction SilentlyContinue
}
