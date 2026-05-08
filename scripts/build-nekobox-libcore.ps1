Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$nekoRoot = Join-Path $repoRoot "NekoBoxForAndroid-main"
$goRoot = "C:\Users\frankniubi\Downloads\leikwan-v3\.codex-temp\go"
$goPath = "C:\Users\frankniubi\Downloads\leikwan-v3\.codex-temp\gopath"
$sdkRoot = "C:\Users\frankniubi\Android\Sdk"
$ndkRoot = Join-Path $sdkRoot "ndk\27.1.12297006"
$gitBash = "C:\Program Files\Git\bin\bash.exe"

$singBoxPath = Join-Path $repoRoot "sing-box"
$libNekoPath = Join-Path $repoRoot "libneko"
$gomobileExe = Join-Path $goPath "bin\gomobile-matsuri.exe"
$gobindExe = Join-Path $goPath "bin\gobind-matsuri.exe"

function Ensure-GitCheckout {
    param(
        [string]$Path,
        [string]$Remote,
        [string]$Commit
    )

    if (!(Test-Path $Path)) {
        git clone --no-checkout $Remote $Path
    }

    Push-Location $Path
    try {
        git checkout $Commit
    } finally {
        Pop-Location
    }
}

Ensure-GitCheckout -Path $singBoxPath -Remote "https://github.com/MatsuriDayo/sing-box.git" -Commit "aed32ee3066cdbc7d471e3e0415c5134088962df"
Ensure-GitCheckout -Path $libNekoPath -Remote "https://github.com/MatsuriDayo/libneko.git" -Commit "1c47a3af71990a7b2192e03292b4d246c308ef0b"

if (!(Test-Path $gomobileExe) -or !(Test-Path $gobindExe)) {
    $bashInit = @'
cd /c/Users/frankniubi/Downloads/leikwan-br1/NekoBoxForAndroid-main/libcore
export PATH="$PATH:/c/Users/frankniubi/Downloads/leikwan-v3/.codex-temp/go/bin:/c/Users/frankniubi/Downloads/leikwan-v3/.codex-temp/gopath/bin"
export GOPATH=/c/Users/frankniubi/Downloads/leikwan-v3/.codex-temp/gopath
export ANDROID_HOME=/c/Users/frankniubi/Android/Sdk
export ANDROID_NDK_HOME=/c/Users/frankniubi/Android/Sdk/ndk/27.1.12297006
export NDK=/c/Users/frankniubi/Android/Sdk/ndk/27.1.12297006
./init.sh
'@
    & $gitBash -lc $bashInit
}

$env:PATH = "$($goRoot)\bin;$($goPath)\bin;$env:PATH"
$env:GOPATH = $goPath
$env:ANDROID_HOME = $sdkRoot
$env:ANDROID_NDK_HOME = $ndkRoot
$env:NDK = $ndkRoot
$env:GOBIND = "gobind-matsuri"

Push-Location (Join-Path $nekoRoot "libcore")
try {
    $cachePath = (Join-Path (Get-Location) ".build")
    & $gomobileExe bind -x -v -androidapi 21 -cache $cachePath -trimpath -ldflags="-s -w" -tags="with_conntrack,with_gvisor,with_quic,with_wireguard,with_utls,with_clash_api" .
    if ($LASTEXITCODE -ne 0) { throw "gomobile-matsuri bind failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

$appLibs = Join-Path $nekoRoot "app\libs"
New-Item -ItemType Directory -Force -Path $appLibs | Out-Null
Copy-Item (Join-Path $nekoRoot "libcore\libcore.aar") (Join-Path $appLibs "libcore.aar") -Force

Get-Item (Join-Path $appLibs "libcore.aar") | Select-Object FullName, Length, LastWriteTime
