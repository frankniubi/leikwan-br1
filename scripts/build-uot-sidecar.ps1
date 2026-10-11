[CmdletBinding()]
param(
    [string]$Version = "2026.09.17.1",
    [ValidateSet("linux", "windows", "darwin")]
    [string]$OS = "linux",
    [ValidateSet("amd64", "arm64")]
    [string]$Arch = "amd64",
    # Optional explicit output path; the default follows the artifacts/<tool> convention.
    [string]$OutputPath = "",
    # Optional override for the stamped build identity (matches build-ultra-linux.ps1).
    [string]$ShortSha = "",
    # Build every target the fleet needs (linux/amd64, linux/arm64, windows/amd64, darwin/arm64).
    [switch]$All,
    # Skip the byte-for-byte rebuild comparison (only useful to save a few seconds locally).
    [switch]$SkipReproducibilityCheck
)

# Builds the Go external-UoT sidecar that is wire-compatible with
# sidecar-agent/leikwan_external_uot.py. CGO is disabled so the binaries stay
# static and run on the minimal sidecar hosts.

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $repoRoot "tools\uot-sidecar"

if (-not (Test-Path -LiteralPath (Join-Path $sourceRoot "go.mod"))) {
    throw "missing Go module at $sourceRoot"
}

$shortSha = $ShortSha
if ([string]::IsNullOrWhiteSpace($shortSha)) {
    # $LASTEXITCODE is only meaningful immediately after git ran.
    $shortSha = git -C $repoRoot rev-parse --short HEAD
    if ($LASTEXITCODE -ne 0) { $shortSha = "" }
}
if ([string]::IsNullOrWhiteSpace($shortSha)) { $shortSha = "unknown" }
$fullVersion = "$($Version).$shortSha"

function Build-Sidecar {
    param([string]$TargetOS, [string]$TargetArch, [string]$Path)

    $suffix = if ($TargetOS -eq "windows") { ".exe" } else { "" }
    if ([string]::IsNullOrWhiteSpace($Path)) {
        $relative = "artifacts\uot-sidecar\uot-sidecar_{0}_{1}{2}" -f $TargetOS, $TargetArch, $suffix
        $Path = Join-Path $repoRoot $relative
    }
    $resolved = [System.IO.Path]::GetFullPath($Path)
    $outputDir = Split-Path -Parent $resolved
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
    $candidate = Join-Path $outputDir (".uot-sidecar-{0}-{1}.new" -f $TargetOS, $PID)

    $previous = @{}
    foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name)
    }
    try {
        $env:GOOS = $TargetOS
        $env:GOARCH = $TargetArch
        $env:CGO_ENABLED = "0"
        $ldflags = "-s -w -X main.sidecarBuildVersion=$fullVersion"
        Push-Location $sourceRoot
        try {
            Write-Host "building uot-sidecar for $TargetOS/$TargetArch -> $resolved"
            # -buildvcs=false keeps the artifact independent of git dirtiness so the
            # recorded sha256 stays reproducible for a given source tree.
            & go build -trimpath -buildvcs=false -ldflags $ldflags -o $candidate .
            if ($LASTEXITCODE -ne 0) { throw "go build failed for $TargetOS/$TargetArch" }
        } finally {
            Pop-Location
        }
    } finally {
        foreach ($name in $previous.Keys) {
            [Environment]::SetEnvironmentVariable($name, $previous[$name])
        }
    }

    if (-not (Test-Path -LiteralPath $candidate)) { throw "build produced no artifact" }
    Move-Item -LiteralPath $candidate -Destination $resolved -Force

    $sha256 = (Get-FileHash -LiteralPath $resolved -Algorithm SHA256).Hash.ToLowerInvariant()
    $md5 = (Get-FileHash -LiteralPath $resolved -Algorithm MD5).Hash.ToLowerInvariant()
    $size = (Get-Item -LiteralPath $resolved).Length

    if (-not $SkipReproducibilityCheck) {
        $reproPath = Join-Path $outputDir (".uot-sidecar-{0}-{1}.repro" -f $TargetOS, $PID)
        $previous = @{}
        foreach ($name in @("GOOS", "GOARCH", "CGO_ENABLED")) {
            $previous[$name] = [Environment]::GetEnvironmentVariable($name)
        }
        try {
            $env:GOOS = $TargetOS
            $env:GOARCH = $TargetArch
            $env:CGO_ENABLED = "0"
            Push-Location $sourceRoot
            try {
                & go build -trimpath -buildvcs=false -ldflags $ldflags -o $reproPath .
                if ($LASTEXITCODE -ne 0) { throw "reproducibility rebuild failed for $TargetOS/$TargetArch" }
            } finally {
                Pop-Location
            }
        } finally {
            foreach ($name in $previous.Keys) {
                [Environment]::SetEnvironmentVariable($name, $previous[$name])
            }
        }
        $reproHash = (Get-FileHash -LiteralPath $reproPath -Algorithm SHA256).Hash.ToLowerInvariant()
        Remove-Item -LiteralPath $reproPath -Force -ErrorAction SilentlyContinue
        if ($reproHash -ne $sha256) {
            throw "artifact is not reproducible for $TargetOS/${TargetArch}: $sha256 vs $reproHash"
        }
    }

    [pscustomobject]@{
        target = "$($TargetOS)_$($TargetArch)"
        path = $resolved
        version = $fullVersion
        sha256 = $sha256
        md5 = $md5
        size = $size
    }
}

$results = @()
if ($All) {
    foreach ($target in @(
        @{ OS = "linux"; Arch = "amd64" },
        @{ OS = "linux"; Arch = "arm64" },
        @{ OS = "windows"; Arch = "amd64" },
        @{ OS = "darwin"; Arch = "arm64" }
    )) {
        $results += Build-Sidecar -TargetOS $target.OS -TargetArch $target.Arch -Path ""
    }
} else {
    $results += Build-Sidecar -TargetOS $OS -TargetArch $Arch -Path $OutputPath
}

# Record the artifacts so a deploy step can verify the exact bytes.
$manifestPath = Join-Path $repoRoot "artifacts\uot-sidecar\manifest.json"
$manifest = @{}
if (Test-Path -LiteralPath $manifestPath) {
    $existing = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    foreach ($property in $existing.PSObject.Properties) {
        $manifest[$property.Name] = $property.Value
    }
}
foreach ($result in $results) {
    $manifest[$result.target] = [pscustomobject]@{
        version = $result.version
        sha256 = $result.sha256
        md5 = $result.md5
        size = $result.size
        file = Split-Path -Leaf $result.path
    }
}
$manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $manifestPath -Encoding ASCII

$results | Format-Table -AutoSize | Out-String -Width 200 | Write-Host
Write-Host "manifest: $manifestPath"

# The stamp is verifiable: a local run of the produced binary must report it.
$nativeArtifact = $results | Where-Object { $_.target -eq "windows_amd64" } | Select-Object -First 1
if ($nativeArtifact) {
    $reported = & $nativeArtifact.path --version
    if ($LASTEXITCODE -ne 0 -or $reported -ne $fullVersion) {
        throw "artifact self-report mismatch: got '$reported', expected '$fullVersion'"
    }
    Write-Host "verified --version = $reported"
}
