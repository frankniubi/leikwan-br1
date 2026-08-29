[CmdletBinding()]
param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [Parameter(Mandatory = $true)]
    [string]$KeystorePath,
    [Parameter(Mandatory = $true)]
    [string]$KeystorePasswordFile,
    [string]$KeyAlias = "leikwan-android-unstable",
    [string]$AndroidSdk = $(
        if ($env:ANDROID_HOME) { $env:ANDROID_HOME }
        elseif ($env:ANDROID_SDK_ROOT) { $env:ANDROID_SDK_ROOT }
        elseif (Test-Path (Join-Path $env:USERPROFILE "Android\Sdk")) { Join-Path $env:USERPROFILE "Android\Sdk" }
        else { Join-Path $env:LOCALAPPDATA "Android\Sdk" }
    )
)

$ErrorActionPreference = "Stop"
$ProjectRoot = [System.IO.Path]::GetFullPath($ProjectRoot)
$KeystorePath = [System.IO.Path]::GetFullPath($KeystorePath)
$KeystorePasswordFile = [System.IO.Path]::GetFullPath($KeystorePasswordFile)

foreach ($path in @($KeystorePath, $KeystorePasswordFile)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required signing input not found: $path"
    }
}

$buildToolsRoot = Join-Path $AndroidSdk "build-tools"
$buildTools = Get-ChildItem -LiteralPath $buildToolsRoot -Directory |
    Sort-Object { [version]$_.Name } -Descending |
    Select-Object -First 1
if ($null -eq $buildTools) {
    throw "Android build-tools not found under $buildToolsRoot"
}

$zipalign = Join-Path $buildTools.FullName "zipalign.exe"
$apksigner = Join-Path $buildTools.FullName "apksigner.bat"
$gradle = Join-Path $ProjectRoot "leikwan-android\gradlew.bat"
$androidRoot = Join-Path $ProjectRoot "leikwan-android"
$unsignedApk = Join-Path $androidRoot "app\build\outputs\apk\release\app-release-unsigned.apk"
$alignedApk = Join-Path $ProjectRoot ".tmp-android-client-aligned.apk"
$signedApk = Join-Path $ProjectRoot ".tmp-android-client-signed.apk"
$artifact = Join-Path $ProjectRoot "artifacts\android\leikwan-android-client.apk"
$artifactVerifier = Join-Path $ProjectRoot "scripts\verify-android-ultra-artifact.ps1"
$enrollmentBytecodeVerifier = Join-Path $ProjectRoot "scripts\verify-android-enrollment-bytecode.ps1"
$buildVersionVerifier = Join-Path $ProjectRoot "scripts\verify-android-build-version.ps1"
$signingPassword = (Get-Content -LiteralPath $KeystorePasswordFile -Raw).Trim()
if ([string]::IsNullOrWhiteSpace($signingPassword)) {
    throw "Signing password file is empty"
}
$env:LEIKWAN_ANDROID_STORE_PASSWORD = $signingPassword
$env:LEIKWAN_ANDROID_KEY_PASSWORD = $signingPassword

try {
    Push-Location $androidRoot
    try {
        & $gradle clean assembleRelease --offline --no-daemon
        if ($LASTEXITCODE -ne 0) { throw "Gradle release build failed" }
    } finally {
        Pop-Location
    }

    & $zipalign -f -p 4 $unsignedApk $alignedApk
    if ($LASTEXITCODE -ne 0) { throw "zipalign failed" }

    & $apksigner sign `
        --ks $KeystorePath `
        --ks-key-alias $KeyAlias `
        --ks-pass "env:LEIKWAN_ANDROID_STORE_PASSWORD" `
        --key-pass "env:LEIKWAN_ANDROID_KEY_PASSWORD" `
        --v1-signing-enabled true `
        --v2-signing-enabled true `
        --v3-signing-enabled true `
        --v4-signing-enabled false `
        --out $signedApk `
        $alignedApk
    if ($LASTEXITCODE -ne 0) { throw "apksigner sign failed" }

    & $apksigner verify --verbose --print-certs $signedApk
    if ($LASTEXITCODE -ne 0) { throw "apksigner verify failed" }

    & $artifactVerifier -ApkPath $signedApk
    if ($LASTEXITCODE -ne 0) { throw "Android Ultra artifact verification failed" }

    & $enrollmentBytecodeVerifier -ApkPath $signedApk -AndroidSdk $AndroidSdk
    if ($LASTEXITCODE -ne 0) { throw "Android enrollment bytecode verification failed" }

    & $buildVersionVerifier -ApkPath $signedApk -AndroidSdk $AndroidSdk
    if ($LASTEXITCODE -ne 0) { throw "Android build version verification failed" }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $artifact) | Out-Null
    Copy-Item -LiteralPath $signedApk -Destination $artifact -Force

    $item = Get-Item -LiteralPath $artifact
    [pscustomobject]@{
        artifact = $item.FullName
        sha256 = (Get-FileHash -LiteralPath $artifact -Algorithm SHA256).Hash.ToLowerInvariant()
        size_bytes = $item.Length
    } | ConvertTo-Json -Compress
} finally {
    Remove-Item Env:LEIKWAN_ANDROID_STORE_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:LEIKWAN_ANDROID_KEY_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $alignedApk, $signedApk -Force -ErrorAction SilentlyContinue
}
