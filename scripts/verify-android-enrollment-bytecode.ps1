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
    throw "apkanalyzer not found: $apkAnalyzer"
}

$bytecode = & $apkAnalyzer dex code `
    --class com.leikwan.bridge.api.PanelRepository `
    --method 'performIdentityEnrollment(JLjava/lang/String;Ljava/lang/String;Lkotlin/coroutines/Continuation;)Ljava/lang/Object;' `
    $ApkPath 2>&1
if ($LASTEXITCODE -ne 0) {
    throw "failed to inspect PanelRepository release bytecode: $bytecode"
}
$bytecodeText = $bytecode -join "`n"

$requiredCalls = @(
    'PanelApi;->deviceChallenge',
    'PanelApi;->enrollDeviceIdentity',
    'PanelRepository;->persistEnrollment'
)
foreach ($call in $requiredCalls) {
    if ($bytecodeText -notmatch [regex]::Escape($call)) {
        throw "release bytecode is missing required enrollment call: $call"
    }
}

[pscustomobject]@{
    status = "ok"
    apk = $ApkPath
    verified_calls = $requiredCalls
} | ConvertTo-Json -Compress
