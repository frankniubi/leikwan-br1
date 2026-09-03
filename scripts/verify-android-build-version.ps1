[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ApkPath,
    [string]$AndroidSdk = $(
        if ($env:ANDROID_HOME) { $env:ANDROID_HOME }
        elseif ($env:ANDROID_SDK_ROOT) { $env:ANDROID_SDK_ROOT }
        elseif (Test-Path (Join-Path $env:USERPROFILE "Android\Sdk")) { Join-Path $env:USERPROFILE "Android\Sdk" }
        else { Join-Path $env:LOCALAPPDATA "Android\Sdk" }
    )
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ApkPath = [System.IO.Path]::GetFullPath($ApkPath)
if (-not (Test-Path -LiteralPath $ApkPath -PathType Leaf)) {
    throw "APK not found: $ApkPath"
}

$apkAnalyzer = Join-Path $AndroidSdk "cmdline-tools\latest\bin\apkanalyzer.bat"
if (-not (Test-Path -LiteralPath $apkAnalyzer -PathType Leaf)) {
    $apkAnalyzer = Get-ChildItem -LiteralPath (Join-Path $AndroidSdk "cmdline-tools") `
        -Recurse -Filter "apkanalyzer.bat" -File -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
}
if ([string]::IsNullOrWhiteSpace($apkAnalyzer) -or -not (Test-Path -LiteralPath $apkAnalyzer -PathType Leaf)) {
    throw "apkanalyzer.bat not found under Android SDK: $AndroidSdk"
}

function Invoke-ApkAnalyzer {
    param([string[]]$Arguments)

    $output = & $apkAnalyzer @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "apkanalyzer failed: $($Arguments -join ' ')"
    }
    return ($output -join "`n")
}

function Convert-DexIntLiteral {
    param([string]$Value)

    if ($Value -match '^0x[0-9a-fA-F]+$') {
        return [Convert]::ToInt64($Value.Substring(2), 16)
    }
    return [Convert]::ToInt64($Value)
}

function Read-StaticStringField {
    param([string]$Code, [string]$Name)

    $match = [regex]::Match(
        $Code,
        ('(?m)^\.field public static final {0}:Ljava/lang/String; = "([^"]*)"$' -f [regex]::Escape($Name))
    )
    if (-not $match.Success) { throw "BuildConfig field is missing: $Name" }
    return $match.Groups[1].Value
}

function Read-StaticScalarField {
    param([string]$Code, [string]$Name, [string]$Type)

    $match = [regex]::Match(
        $Code,
        ('(?m)^\.field public static final {0}:{1} = (\S+)$' -f [regex]::Escape($Name), [regex]::Escape($Type))
    )
    if (-not $match.Success) { throw "BuildConfig field is missing: $Name" }
    return $match.Groups[1].Value
}

$manifestApplicationId = (Invoke-ApkAnalyzer @("manifest", "application-id", $ApkPath)).Trim()
$manifestVersionName = (Invoke-ApkAnalyzer @("manifest", "version-name", $ApkPath)).Trim()
$manifestVersionCode = [long](Invoke-ApkAnalyzer @("manifest", "version-code", $ApkPath)).Trim()
$buildConfigCode = Invoke-ApkAnalyzer @("dex", "code", "--class", "com.leikwan.bridge.BuildConfig", $ApkPath)

$buildApplicationId = Read-StaticStringField $buildConfigCode "APPLICATION_ID"
$buildVersionName = Read-StaticStringField $buildConfigCode "VERSION_NAME"
$buildType = Read-StaticStringField $buildConfigCode "BUILD_TYPE"
$buildVersionCode = Convert-DexIntLiteral (Read-StaticScalarField $buildConfigCode "VERSION_CODE" "I")
$debugValue = Read-StaticScalarField $buildConfigCode "DEBUG" "Z"
$qaExceptionsValue = Read-StaticScalarField $buildConfigCode "ALLOW_QA_TEST_DEVICE_EXCEPTIONS" "Z"

if ($manifestApplicationId -ne "com.leikwan.bridge" -or $buildApplicationId -ne $manifestApplicationId) {
    throw "application id mismatch: manifest=$manifestApplicationId BuildConfig=$buildApplicationId"
}
if ($buildVersionName -ne $manifestVersionName -or $buildVersionCode -ne $manifestVersionCode) {
    throw "BuildConfig version does not match APK manifest: manifest=$manifestVersionName/$manifestVersionCode BuildConfig=$buildVersionName/$buildVersionCode"
}
if ($buildType -ne "release" -or $debugValue -notin @("false", "0x0")) {
    throw "test artifact must be a non-debug release build: type=$buildType debug=$debugValue"
}
if ($qaExceptionsValue -notin @("false", "0x0")) {
    throw "release artifact enables QA device exceptions"
}

$dexPackages = Invoke-ApkAnalyzer @("dex", "packages", $ApkPath)
$requiredClasses = @(
    "com.leikwan.bridge.api.PanelRepository",
    "com.leikwan.bridge.runtime.NativeOutboundCapability",
    "com.leikwan.bridge.runtime.NativeOutboundLease",
    "com.leikwan.bridge.service.TunnelOrchestrator",
    "com.leikwan.bridge.ui.connections.ConnectionsScreenKt"
)
foreach ($className in $requiredClasses) {
    if ($dexPackages -notmatch ('(?m)^C[^\r\n]*\s+{0}$' -f [regex]::Escape($className))) {
        throw "required native-outbound class is missing: $className"
    }
}

# The standalone native-outbound test artifact must not accidentally restore
# the retired in-app updater or its prompt from an older worktree/build cache.
$forbiddenClassPatterns = @(
    '(?m)^C[^\r\n]*\s+com\.leikwan\.bridge\.update(?:\.|\$|$)',
    '(?m)^C[^\r\n]*\s+com\.leikwan\.bridge\.ui\.update(?:\.|\$|$)'
)
foreach ($pattern in $forbiddenClassPatterns) {
    if ([regex]::IsMatch($dexPackages, $pattern)) {
        throw "retired Android update component found in test artifact"
    }
}

[pscustomobject]@{
    apk = $ApkPath
    application_id = $manifestApplicationId
    version_name = $manifestVersionName
    version_code = $manifestVersionCode
    build_type = $buildType
    qa_device_exceptions = $false
    required_native_classes = $requiredClasses
    retired_update_classes = 0
    status = "ok"
} | ConvertTo-Json -Compress
