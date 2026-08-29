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

function Dex-HexToInt64 {
    param([string]$Value)
    return [Convert]::ToInt64($Value.Substring(2), 16)
}

$manifestVersionName = (Invoke-ApkAnalyzer @("manifest", "version-name", $ApkPath)).Trim()
$manifestVersionCode = [long](Invoke-ApkAnalyzer @("manifest", "version-code", $ApkPath)).Trim()

$updateStateCode = Invoke-ApkAnalyzer @(
    "dex", "code", "--class", "com.leikwan.bridge.update.AppUpdateUiState", $ApkPath
)
$updateStateConstructor = [regex]::Match(
    $updateStateCode,
    '(?s)\.method public synthetic constructor <init>\(Ljava/lang/String;J.*?\.end method'
).Value
$uiVersionName = [regex]::Match(
    $updateStateConstructor,
    '(?s)\.line 32\s+const-string\s+\S+,\s+"([^"]+)"'
).Groups[1].Value
$uiVersionCodeHex = [regex]::Match(
    $updateStateConstructor,
    'const-wide(?:/\S+)?\s+\S+,\s+(0x[0-9a-f]+)'
).Groups[1].Value
if ($uiVersionName -ne $manifestVersionName -or [string]::IsNullOrWhiteSpace($uiVersionCodeHex)) {
    throw "Update UI bytecode version does not match APK manifest: manifest=$manifestVersionName/$manifestVersionCode bytecode=$uiVersionName/$uiVersionCodeHex"
}
$uiVersionCode = Dex-HexToInt64 $uiVersionCodeHex
if ($uiVersionCode -ne $manifestVersionCode) {
    throw "Update UI bytecode version code does not match APK manifest: manifest=$manifestVersionCode bytecode=$uiVersionCode"
}

$updateServiceCode = Invoke-ApkAnalyzer @(
    "dex", "code", "--class", "com.leikwan.bridge.update.AppUpdateService", $ApkPath
)
$updateServiceConstructor = [regex]::Match(
    $updateServiceCode,
    '(?s)\.method public synthetic constructor <init>\(Landroid/content/Context;.*?\.end method'
).Value
$serviceVersionCodeHex = [regex]::Match(
    $updateServiceConstructor,
    '(?s)and-int/lit8\s+\S+,\s+\S+,\s+0x8.*?const-wide(?:/\S+)?\s+\S+,\s+(0x[0-9a-f]+)'
).Groups[1].Value
if ([string]::IsNullOrWhiteSpace($serviceVersionCodeHex)) {
    throw "Unable to read the update service version code from APK bytecode"
}
$serviceVersionCode = Dex-HexToInt64 $serviceVersionCodeHex
if ($serviceVersionCode -ne $manifestVersionCode) {
    throw "Update service bytecode version code does not match APK manifest: manifest=$manifestVersionCode bytecode=$serviceVersionCode"
}

$repositoryCode = Invoke-ApkAnalyzer @(
    "dex", "code", "--class", "com.leikwan.bridge.api.PanelRepository", $ApkPath
)
$panelVersionName = [regex]::Match(
    $repositoryCode,
    '(?s)new-instance\s+\S+,\s+Lcom/leikwan/bridge/api/PanelDeviceInterceptor;.*?const-string\s+\S+,\s+"([^"]+)".*?invoke-direct.*?PanelDeviceInterceptor;-><init>'
).Groups[1].Value
if ($panelVersionName -ne $manifestVersionName) {
    throw "Panel client bytecode version does not match APK manifest: manifest=$manifestVersionName bytecode=$panelVersionName"
}

[pscustomobject]@{
    apk = $ApkPath
    version_name = $manifestVersionName
    version_code = $manifestVersionCode
    panel_version_name = $panelVersionName
    update_version_name = $uiVersionName
    update_version_code = $serviceVersionCode
} | ConvertTo-Json -Compress
