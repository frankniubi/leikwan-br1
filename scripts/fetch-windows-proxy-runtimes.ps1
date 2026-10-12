param(
    [string]$OutputDir = "artifacts/windows/proxy-runtime",
    [string]$Proxy = "http://127.0.0.1:2080",
    [string]$SingBoxVersion = "1.13.19",
    [string]$ChinaIpList = "CN.conf",
    [string]$GeoIpRuleSet = "geoip-cn.srs",
    [string]$GeoSiteRuleSet = "geosite-cn.srs",
    [switch]$SkipProcessRedirector
)

$ErrorActionPreference = "Stop"

function Download-Verified {
    param(
        [string]$Url,
        [string]$Path,
        [string]$Sha256
    )

    Write-Host "Downloading $Url through $Proxy"
    & curl.exe --proxy $Proxy --fail --location --retry 2 --output $Path $Url
    if ($LASTEXITCODE -ne 0) {
        throw "download failed: $Url"
    }

    $actual = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $Sha256.ToLowerInvariant()) {
        throw "sha256 mismatch for $([System.IO.Path]::GetFileName($Path))"
    }
}

New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
$scratch = Join-Path ([System.IO.Path]::GetTempPath()) ("leikwan-proxy-runtime-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $scratch -Force | Out-Null

try {
    $singZip = Join-Path $scratch "sing-box.zip"
    $singUrl = "https://github.com/SagerNet/sing-box/releases/download/v$SingBoxVersion/sing-box-$SingBoxVersion-windows-amd64.zip"
    $singSha = switch ($SingBoxVersion) {
        "1.13.19" { "e011a4def2f5e2b143ed54adb2b1a20a6be407806ab4442f3667f1dd817a2c8d"; break }
        default { throw "no pinned SHA256 for sing-box $SingBoxVersion; add it explicitly before downloading" }
    }
    Download-Verified $singUrl $singZip $singSha
    $singExtract = Join-Path $scratch "sing-box"
    Expand-Archive -LiteralPath $singZip -DestinationPath $singExtract -Force
    $singExe = Get-ChildItem -LiteralPath $singExtract -Recurse -Filter "sing-box.exe" | Select-Object -First 1
    if (-not $singExe) { throw "sing-box.exe missing from release archive" }
    Copy-Item -LiteralPath $singExe.FullName -Destination (Join-Path $OutputDir "sing-box.exe") -Force

    if (-not (Test-Path -LiteralPath $ChinaIpList)) {
        throw "China IP list missing: $ChinaIpList"
    }
    Copy-Item -LiteralPath $ChinaIpList -Destination (Join-Path $OutputDir "CN.conf") -Force
    foreach ($ruleSet in @($GeoIpRuleSet, $GeoSiteRuleSet)) {
        if (-not (Test-Path -LiteralPath $ruleSet)) {
            throw "sing-box rule-set missing: $ruleSet"
        }
    }
    Copy-Item -LiteralPath $GeoIpRuleSet -Destination (Join-Path $OutputDir "geoip-cn.srs") -Force
    Copy-Item -LiteralPath $GeoSiteRuleSet -Destination (Join-Path $OutputDir "geosite-cn.srs") -Force

    if (-not $SkipProcessRedirector) {
        $netchArchive = Join-Path $scratch "Netch.7z"
        Download-Verified `
            "https://github.com/netchx/netch/releases/download/1.9.7/Netch.7z" `
            $netchArchive `
            "692aa6ddd20ed98cf9dd1c51c0495206c8ea3aeef9e9f50fb1de94eca28be5a8"
        $netchExtract = Join-Path $scratch "netch"
        New-Item -ItemType Directory -Path $netchExtract -Force | Out-Null
        & tar.exe -xf $netchArchive -C $netchExtract
        if ($LASTEXITCODE -ne 0) { throw "could not extract Netch archive with tar.exe" }

        $redirector = Get-ChildItem -LiteralPath $netchExtract -Recurse -Filter "Redirector.bin" | Select-Object -First 1
        $driver = Get-ChildItem -LiteralPath $netchExtract -Recurse -Filter "nfdriver.sys" | Select-Object -First 1
        $nfapi = Get-ChildItem -LiteralPath $netchExtract -Recurse -Filter "nfapi.dll" | Select-Object -First 1
        if (-not $redirector -or -not $driver -or -not $nfapi) {
            throw "Netch process redirector payload is incomplete"
        }
        Copy-Item -LiteralPath $redirector.FullName -Destination (Join-Path $OutputDir "Redirector.bin") -Force
        Copy-Item -LiteralPath $driver.FullName -Destination (Join-Path $OutputDir "netfilter2.sys") -Force
        Copy-Item -LiteralPath $nfapi.FullName -Destination (Join-Path $OutputDir "nfapi.dll") -Force
    }

    $manifest = [ordered]@{
        sing_box = [ordered]@{
            version = $SingBoxVersion
            source = "https://github.com/SagerNet/sing-box"
            license = "GPL-3.0"
            sha256 = (Get-FileHash (Join-Path $OutputDir "sing-box.exe") -Algorithm SHA256).Hash.ToLowerInvariant()
        }
        process_redirector = [ordered]@{
            source = "https://github.com/netchx/netch"
            version = "1.9.7"
            license = "GPL-3.0"
            included = (-not $SkipProcessRedirector)
        }
        china_ip_list = [ordered]@{
            source = "CN.conf"
            format = 'route <cidr> via "lo";'
            sha256 = (Get-FileHash (Join-Path $OutputDir "CN.conf") -Algorithm SHA256).Hash.ToLowerInvariant()
        }
        china_rule_sets = [ordered]@{
            source = "https://github.com/SagerNet/sing-geoip, https://github.com/SagerNet/sing-geosite"
            license = "GPL-3.0-or-later"
            geoip_cn_sha256 = (Get-FileHash (Join-Path $OutputDir "geoip-cn.srs") -Algorithm SHA256).Hash.ToLowerInvariant()
            geosite_cn_sha256 = (Get-FileHash (Join-Path $OutputDir "geosite-cn.srs") -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    }
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $OutputDir "runtime-manifest.json") -Encoding UTF8
    Write-Host "Windows proxy runtimes staged in $OutputDir"
}
finally {
    if (Test-Path -LiteralPath $scratch) {
        Remove-Item -LiteralPath $scratch -Recurse -Force -ErrorAction SilentlyContinue
    }
}
