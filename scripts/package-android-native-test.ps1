[CmdletBinding()]
param(
    [string]$OutputPath = "",
    [string]$Keystore = "",
    [string]$KeyAlias = "leikwan-android-release",
    [string]$PasswordFile = "",
    [string]$Lineage = "",
    [string]$NextSignerKeystore = "",
    [string]$NextSignerKeyAlias = "",
    [string]$NextSignerPasswordFile = "",
    [string]$SdkRoot = "",
    [string]$RuntimeVersion = "2026.09.01.1",
    [switch]$SkipRuntimeBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$androidRoot = Join-Path $repoRoot "leikwan-android"
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot "artifacts\android\native-outbound-test-20260901.apk"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)

if ([string]::IsNullOrWhiteSpace($Keystore)) {
    $Keystore = Join-Path $env:USERPROFILE ".android\leikwan-android-release.jks"
}
if ([string]::IsNullOrWhiteSpace($PasswordFile)) {
    $PasswordFile = [System.IO.Path]::ChangeExtension($Keystore, ".pass")
}
$Keystore = [System.IO.Path]::GetFullPath($Keystore)
$PasswordFile = [System.IO.Path]::GetFullPath($PasswordFile)
if (-not (Test-Path -LiteralPath $Keystore -PathType Leaf)) { throw "keystore not found: $Keystore" }
if (-not (Test-Path -LiteralPath $PasswordFile -PathType Leaf)) { throw "keystore password file not found" }

# Key rotation (v3 lineage): every build since 2026-09-11 must be signed with the
# release key plus the lineage file, and the previous (unstable) key must co-sign
# the v1/v2 blocks via --next-signer so Android 7-12 devices keep accepting the
# update. Never sign with the old key alone again.
if ([string]::IsNullOrWhiteSpace($Lineage)) {
    $Lineage = Join-Path $env:USERPROFILE ".android\leikwan-android-release-lineage.pem"
}
$Lineage = [System.IO.Path]::GetFullPath($Lineage)
if (-not (Test-Path -LiteralPath $Lineage -PathType Leaf)) { throw "signing lineage not found: $Lineage" }
if ([string]::IsNullOrWhiteSpace($NextSignerKeystore)) {
    $NextSignerKeystore = Join-Path $env:USERPROFILE ".android\leikwan-android-unstable-20260827.jks"
}
$NextSignerKeystore = [System.IO.Path]::GetFullPath($NextSignerKeystore)
if ([string]::IsNullOrWhiteSpace($NextSignerKeyAlias)) { $NextSignerKeyAlias = "leikwan-android-unstable" }
if ([string]::IsNullOrWhiteSpace($NextSignerPasswordFile)) {
    $NextSignerPasswordFile = [System.IO.Path]::ChangeExtension($NextSignerKeystore, ".pass")
}
$NextSignerPasswordFile = [System.IO.Path]::GetFullPath($NextSignerPasswordFile)
if (-not (Test-Path -LiteralPath $NextSignerKeystore -PathType Leaf)) { throw "next-signer keystore not found" }
if (-not (Test-Path -LiteralPath $NextSignerPasswordFile -PathType Leaf)) { throw "next-signer password file not found" }

$sdkCandidates = if ([string]::IsNullOrWhiteSpace($SdkRoot)) {
    @($env:ANDROID_HOME, $env:ANDROID_SDK_ROOT, (Join-Path $env:LOCALAPPDATA "Android\Sdk"), (Join-Path $env:USERPROFILE "Android\Sdk"))
} else {
    @([System.IO.Path]::GetFullPath($SdkRoot))
}
function Test-SdkForPackaging([string]$Root) {
    if ([string]::IsNullOrWhiteSpace($Root) -or -not (Test-Path (Join-Path $Root "platform-tools\adb.exe"))) {
        return $false
    }
    $latestAnalyzer = Join-Path $Root "cmdline-tools\latest\bin\apkanalyzer.bat"
    if (Test-Path -LiteralPath $latestAnalyzer -PathType Leaf) { return $true }
    return $null -ne (Get-ChildItem -LiteralPath (Join-Path $Root "cmdline-tools") `
        -Recurse -Filter "apkanalyzer.bat" -File -ErrorAction SilentlyContinue | Select-Object -First 1)
}
$sdk = $sdkCandidates | Where-Object { Test-SdkForPackaging $_ } | Select-Object -First 1
if ([string]::IsNullOrWhiteSpace($sdk)) { throw "Android SDK not found" }
$buildTools = Get-ChildItem -LiteralPath (Join-Path $sdk "build-tools") -Directory -ErrorAction Stop |
    Sort-Object { try { [Version]$_.Name } catch { [Version]"0.0" } } -Descending |
    Where-Object { (Test-Path (Join-Path $_.FullName "zipalign.exe")) -and (Test-Path (Join-Path $_.FullName "apksigner.bat")) } |
    Select-Object -First 1
if ($null -eq $buildTools) { throw "Android build-tools with zipalign/apksigner not found" }
$zipalign = Join-Path $buildTools.FullName "zipalign.exe"
$apksigner = Join-Path $buildTools.FullName "apksigner.bat"

$manifestPath = Join-Path $repoRoot "artifacts\versions.json"
$manifestHashBefore = if (Test-Path -LiteralPath $manifestPath -PathType Leaf) {
    (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash
} else { "missing" }
$stagingRoot = Join-Path $repoRoot ("artifacts\android\.native-package-{0}" -f $PID)
$alignedPath = Join-Path $stagingRoot "app-release-aligned.apk"
$signedPath = Join-Path $stagingRoot "app-release-signed.apk"
$evidencePath = "$OutputPath.acceptance.json"
New-Item -ItemType Directory -Path $stagingRoot -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path -Parent $OutputPath) -Force | Out-Null

$oldSignPass = [Environment]::GetEnvironmentVariable("LK_SIGN_PASS", "Process")
$oldNextPass = [Environment]::GetEnvironmentVariable("LK_NEXT_PASS", "Process")
$oldAndroidHome = [Environment]::GetEnvironmentVariable("ANDROID_HOME", "Process")
$oldAndroidSdkRoot = [Environment]::GetEnvironmentVariable("ANDROID_SDK_ROOT", "Process")
$result = $null
try {
    $env:ANDROID_HOME = $sdk
    $env:ANDROID_SDK_ROOT = $sdk
    $env:LK_SIGN_PASS = (Get-Content -LiteralPath $PasswordFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($env:LK_SIGN_PASS)) { throw "keystore password is empty" }
    $env:LK_NEXT_PASS = (Get-Content -LiteralPath $NextSignerPasswordFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($env:LK_NEXT_PASS)) { throw "next-signer keystore password is empty" }

    if (-not $SkipRuntimeBuild) {
        & (Join-Path $repoRoot "scripts\build-ultra-android.ps1") -Version $RuntimeVersion -Architectures "arm64-v8a,x86_64"
        if ($LASTEXITCODE -ne 0) { throw "Android Ultra runtime matrix build failed" }
    }

    Push-Location $androidRoot
    try {
        # A clean release build is the anti-stale boundary: no previous APK,
        # JNI slice, generated manifest, or resource can be merged into this
        # test artifact.
        & ".\gradlew.bat" "clean" ":app:assembleRelease" "--offline" "--no-daemon" "--max-workers=1"
        if ($LASTEXITCODE -ne 0) { throw "Gradle release build failed" }
    } finally { Pop-Location }

    $unsigned = Join-Path $androidRoot "app\build\outputs\apk\release\app-release-unsigned.apk"
    if (-not (Test-Path -LiteralPath $unsigned -PathType Leaf)) { throw "unsigned release APK not found" }
    & $zipalign "-p" "-f" "4" $unsigned $alignedPath
    if ($LASTEXITCODE -ne 0) { throw "zipalign failed" }
    & $apksigner sign "--ks" $Keystore "--ks-key-alias" $KeyAlias `
        "--ks-pass" "env:LK_SIGN_PASS" "--key-pass" "env:LK_SIGN_PASS" `
        "--lineage" $Lineage `
        "--next-signer" "--ks" $NextSignerKeystore "--ks-key-alias" $NextSignerKeyAlias `
        "--ks-pass" "env:LK_NEXT_PASS" "--key-pass" "env:LK_NEXT_PASS" `
        "--v1-signing-enabled" "true" "--v2-signing-enabled" "true" `
        "--v3-signing-enabled" "true" "--v4-signing-enabled" "false" `
        "--out" $signedPath $alignedPath
    if ($LASTEXITCODE -ne 0) { throw "apksigner sign failed" }
    $verifyOutput = (& $apksigner verify "--verbose" "--min-sdk-version" "26" $signedPath 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0 -or $verifyOutput -notmatch "(?i)v2.*true" -or $verifyOutput -notmatch "(?i)v3.*true") {
        throw "APK signature verification failed"
    }

    & (Join-Path $repoRoot "scripts\verify-android-native-artifacts.ps1") -Apk $signedPath
    if ($LASTEXITCODE -ne 0) { throw "Android native ABI artifact verification failed" }
    & (Join-Path $repoRoot "scripts\verify-android-ultra-artifact.ps1") -ApkPath $signedPath
    if ($LASTEXITCODE -ne 0) { throw "Android Ultra artifact verification failed" }
    $buildContractOutput = & (Join-Path $repoRoot "scripts\verify-android-build-version.ps1") `
        -ApkPath $signedPath -AndroidSdk $sdk
    if ($LASTEXITCODE -ne 0) { throw "Android build contract verification failed" }
    $buildContract = $buildContractOutput | Select-Object -Last 1 | ConvertFrom-Json

    Copy-Item -LiteralPath $signedPath -Destination $OutputPath -Force
    $manifestHashAfter = if (Test-Path -LiteralPath $manifestPath -PathType Leaf) {
        (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash
    } else { "missing" }
    if ($manifestHashBefore -ne $manifestHashAfter) {
        throw "package flow modified artifacts/versions.json; refusing test artifact"
    }
    $apkHash = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $apkInfo = Get-Item -LiteralPath $OutputPath
    $result = [ordered]@{
        status = "ok"
        artifact = $OutputPath
        sha256 = $apkHash
        size_bytes = $apkInfo.Length
        signature_v2_v3 = $true
        abi_matrix = @("arm64-v8a", "x86_64")
        runtime_version = $RuntimeVersion
        version_name = $buildContract.version_name
        version_code = $buildContract.version_code
        release_contract_checked = $true
        retired_update_classes = $buildContract.retired_update_classes
        update_manifest_unchanged = $true
        update_push_touched = $false
    }
    $result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $evidencePath -Encoding utf8
    $result | ConvertTo-Json -Compress
} finally {
    if ($null -eq $oldSignPass) { Remove-Item Env:LK_SIGN_PASS -ErrorAction SilentlyContinue } else { $env:LK_SIGN_PASS = $oldSignPass }
    if ($null -eq $oldNextPass) { Remove-Item Env:LK_NEXT_PASS -ErrorAction SilentlyContinue } else { $env:LK_NEXT_PASS = $oldNextPass }
    if ($null -eq $oldAndroidHome) { Remove-Item Env:ANDROID_HOME -ErrorAction SilentlyContinue } else { $env:ANDROID_HOME = $oldAndroidHome }
    if ($null -eq $oldAndroidSdkRoot) { Remove-Item Env:ANDROID_SDK_ROOT -ErrorAction SilentlyContinue } else { $env:ANDROID_SDK_ROOT = $oldAndroidSdkRoot }
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force -ErrorAction SilentlyContinue
}
