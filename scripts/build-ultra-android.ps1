[CmdletBinding()]
param(
    [string]$Version = "2026.08.27.1",
    [string]$OutputPath = "",
    [ValidateSet("arm64-v8a", "x86_64")]
    [string]$Abi = "arm64-v8a",
    [string]$Architectures = "",
    [switch]$SkipReproducibilityCheck
)

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\ultra-client"
# Same fingerprint convention as build-ultra-macos.ps1: <version>.<shortsha>.
$shortSha = git -C $repoRoot rev-parse --short HEAD
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = "unknown" }
$FullVersion = "$Version.$shortSha"
if (-not [string]::IsNullOrWhiteSpace($Architectures)) {
    foreach ($architecture in $Architectures.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ }) {
        $mappedAbi = switch ($architecture) {
            "arm64" { "arm64-v8a" }
            "arm64-v8a" { "arm64-v8a" }
            "amd64" { "x86_64" }
            "x86_64" { "x86_64" }
            default { throw "unsupported Android architecture: $architecture" }
        }
        $childParams = @{ Version = $Version; Abi = $mappedAbi }
        if ($SkipReproducibilityCheck) { $childParams.SkipReproducibilityCheck = $true }
        & $PSCommandPath @childParams
        if ($LASTEXITCODE -ne 0) { throw "Android $mappedAbi build failed" }
    }
    return
}
$abiConfig = switch ($Abi) {
    "arm64-v8a" {
        [pscustomobject]@{
            GoArch = "arm64"
            CgoEnabled = "0"
            ElfMachine = 183
            ArtifactName = "ultra-client_android_arm64"
        }
    }
    "x86_64" {
        [pscustomobject]@{
            GoArch = "amd64"
            CgoEnabled = "1"
            ElfMachine = 62
            ArtifactName = "ultra-client_android_x86_64"
        }
    }
    default { throw "unsupported Android ABI: $Abi" }
}
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot "artifacts\ultra\$($abiConfig.ArtifactName)"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDir = Split-Path -Parent $OutputPath
$assetPath = Join-Path $repoRoot "leikwan-android\app\src\main\assets\runtime\$Abi\ultra-client"
$jniPath = Join-Path $repoRoot "leikwan-android\app\src\main\jniLibs\$Abi\libultra_client.so"
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
foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED", "CC", "CXX")) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, "Process")
}

function Resolve-X86AndroidToolchain {
    $ndkRoots = @(
        $env:ANDROID_NDK_HOME,
        $env:ANDROID_NDK_ROOT
    )
    foreach ($sdkRoot in @(
        $env:ANDROID_SDK_ROOT,
        $env:ANDROID_HOME,
        (Join-Path $env:LOCALAPPDATA "Android\Sdk"),
        (Join-Path $env:USERPROFILE "Android\Sdk")
    )) {
        if ([string]::IsNullOrWhiteSpace($sdkRoot)) { continue }
        $ndkParent = Join-Path $sdkRoot "ndk"
        if (Test-Path -LiteralPath $ndkParent -PathType Container) {
            $ndkRoots += Get-ChildItem -LiteralPath $ndkParent -Directory -ErrorAction SilentlyContinue |
                Sort-Object Name -Descending |
                ForEach-Object FullName
        }
    }
    foreach ($ndkRoot in ($ndkRoots | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -Unique)) {
        $bin = Join-Path $ndkRoot "toolchains\llvm\prebuilt\windows-x86_64\bin"
        $cc = Join-Path $bin "x86_64-linux-android21-clang.cmd"
        $cxx = Join-Path $bin "x86_64-linux-android21-clang++.cmd"
        if ((Test-Path -LiteralPath $cc -PathType Leaf) -and (Test-Path -LiteralPath $cxx -PathType Leaf)) {
            return [pscustomobject]@{ Cc = $cc; Cxx = $cxx; NdkRoot = $ndkRoot }
        }
    }
    throw "x86_64 Android build requires an Android NDK x86_64 clang toolchain"
}

function Invoke-GarbleBuild([string]$Destination) {
    & $garblePath -literals -tiny "-seed=$seed" build -buildmode=pie -trimpath -buildvcs=false "-ldflags=-s -w -buildid= -X main.runtimeBuildVersion=$FullVersion" -o $Destination .
    if ($LASTEXITCODE -ne 0) {
        throw "garble Android $Abi build failed"
    }
}

function Assert-AndroidElf([string]$Path) {
    $header = [System.IO.File]::ReadAllBytes($Path)
    if ($header.Length -lt 20 -or $header[0] -ne 0x7f -or $header[1] -ne [byte][char]'E' -or $header[2] -ne [byte][char]'L' -or $header[3] -ne [byte][char]'F') {
        throw "runtime is not an ELF binary"
    }
    if ($header[4] -ne 2 -or $header[5] -ne 1) {
        throw "runtime is not ELF64 little-endian"
    }
    $machine = [BitConverter]::ToUInt16($header, 18)
    if ($machine -ne $abiConfig.ElfMachine) {
        throw "runtime machine does not match $Abi (e_machine=$machine)"
    }
}

function Move-WithRetry([string]$Source, [string]$Destination) {
    $lastError = $null
    for ($attempt = 1; $attempt -le 10; $attempt++) {
        try {
            Move-Item -LiteralPath $Source -Destination $Destination -Force -ErrorAction Stop
            return
        } catch {
            $lastError = $_
            if ($attempt -lt 10) { Start-Sleep -Seconds 2 }
        }
    }
    throw "unable to replace locked Android runtime after 10 attempts: $Destination`n$lastError"
}

function Copy-WithRetry([string]$Source, [string]$Destination) {
    $lastError = $null
    for ($attempt = 1; $attempt -le 10; $attempt++) {
        try {
            Copy-Item -LiteralPath $Source -Destination $Destination -Force -ErrorAction Stop
            return
        } catch {
            $lastError = $_
            if ($attempt -lt 10) { Start-Sleep -Seconds 2 }
        }
    }
    throw "unable to copy Android runtime after 10 attempts: $Destination`n$lastError"
}

try {
    $env:GOOS = "android"
    $env:GOARCH = $abiConfig.GoArch
    $env:CGO_ENABLED = $abiConfig.CgoEnabled
    if ($Abi -eq "x86_64") {
        $toolchain = Resolve-X86AndroidToolchain
        $env:CC = $toolchain.Cc
        $env:CXX = $toolchain.Cxx
    }
    Push-Location $sourceRoot
    try {
        Invoke-GarbleBuild $candidatePath
        Assert-AndroidElf $candidatePath
        if (-not $SkipReproducibilityCheck) {
            Invoke-GarbleBuild $reproPath
            Assert-AndroidElf $reproPath
            $candidateHash = (Get-FileHash -LiteralPath $candidatePath -Algorithm SHA256).Hash
            $reproHash = (Get-FileHash -LiteralPath $reproPath -Algorithm SHA256).Hash
            if ($candidateHash -ne $reproHash) {
                throw "garble reproducibility check failed"
            }
        }
    } finally {
        Pop-Location
    }

    Move-WithRetry $candidatePath $OutputPath
    Assert-AndroidElf $OutputPath
    Copy-WithRetry $OutputPath $assetPath
    Copy-WithRetry $OutputPath $jniPath
    $artifactHash = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    foreach ($path in @($assetPath, $jniPath)) {
        Assert-AndroidElf $path
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $artifactHash) {
            throw "runtime copy hash mismatch: $path"
        }
    }
    $artifact = Get-Item -LiteralPath $OutputPath
    [pscustomobject]@{
        version = $Version
        abi = $Abi
        sha256 = $artifactHash
        size_bytes = $artifact.Length
        go_version = (& go version)
        obfuscator = "garble-$garbleVersion"
        reproducible = -not $SkipReproducibilityCheck
        asset_path = $assetPath
        jni_path = $jniPath
    } | ConvertTo-Json -Compress
} finally {
    foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED", "CC", "CXX")) {
        $oldValue = $previousEnvironment[$name]
        if ($null -eq $oldValue) { [Environment]::SetEnvironmentVariable($name, $null, "Process") } else { [Environment]::SetEnvironmentVariable($name, $oldValue, "Process") }
    }
    Remove-Item -LiteralPath $candidatePath, $reproPath -Force -ErrorAction SilentlyContinue
}
