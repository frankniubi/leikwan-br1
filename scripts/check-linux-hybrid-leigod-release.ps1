[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Timings = [System.Collections.Generic.List[object]]::new()

function Convert-ToWslPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    $fullPath = [System.IO.Path]::GetFullPath($Path)
    if ($fullPath.Length -lt 3 -or $fullPath[1] -ne ':') {
        throw "Only absolute Windows drive paths are supported: $fullPath"
    }
    $drive = $fullPath.Substring(0, 1).ToLowerInvariant()
    $rest = $fullPath.Substring(2).Replace('\', '/')
    return "/mnt/$drive$rest"
}

function Convert-ToBashLiteral {
    param([Parameter(Mandatory = $true)][string]$Value)

    if ($Value.Contains("'")) {
        throw 'Single quotes are not supported in release-gate paths.'
    }
    return "'$Value'"
}

$WslRepoRoot = Convert-ToWslPath $RepoRoot
$WslRepoLiteral = Convert-ToBashLiteral $WslRepoRoot

function Invoke-NativeChecked {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    $previousErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $FilePath @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
    }
    if ($exitCode -ne 0) {
        $output | ForEach-Object { Write-Host $_ }
        throw "$FilePath exited with code $exitCode"
    }
}

function Invoke-WslChecked {
    param([Parameter(Mandatory = $true)][string]$Command)

    $fullCommand = "cd $WslRepoLiteral && $Command"
    $previousErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& wsl.exe -e bash -lc $fullCommand 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
    }
    if ($exitCode -ne 0) {
        $output | ForEach-Object { Write-Host $_ }
        throw "WSL command exited with code $exitCode"
    }
}

function Invoke-Stage {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][scriptblock]$Action
    )

    Write-Host "[RUN] $Name"
    $timer = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        & $Action
        $timer.Stop()
        $Timings.Add([pscustomobject]@{ Name = $Name; Seconds = [math]::Round($timer.Elapsed.TotalSeconds, 1) })
        Write-Host "[OK]  $Name ($([math]::Round($timer.Elapsed.TotalSeconds, 1))s)"
    }
    catch {
        $timer.Stop()
        Write-Host "[FAIL] $Name ($([math]::Round($timer.Elapsed.TotalSeconds, 1))s)" -ForegroundColor Red
        throw
    }
}

function Invoke-PhpFiles {
    param([Parameter(Mandatory = $true)][string[]]$Paths)

    $arguments = ($Paths | ForEach-Object { Convert-ToBashLiteral $_ }) -join ' '
    Invoke-WslChecked ('for test_file in {0}; do php "$test_file"; done' -f $arguments)
}

function Invoke-PythonDiscovery {
    param(
        [Parameter(Mandatory = $true)][string]$StartDirectory,
        [Parameter(Mandatory = $true)][string]$Pattern
    )

    Invoke-NativeChecked 'python' @(
        '-m', 'unittest', 'discover',
        '-s', (Join-Path $RepoRoot $StartDirectory),
        '-p', $Pattern,
        '-v'
    )
}

function Invoke-WslPythonDiscovery {
    param(
        [Parameter(Mandatory = $true)][string]$StartDirectory,
        [Parameter(Mandatory = $true)][string]$Pattern
    )

    $startLiteral = Convert-ToBashLiteral $StartDirectory
    $patternLiteral = Convert-ToBashLiteral $Pattern
    Invoke-WslChecked "nice -n 10 python3 -m unittest discover -s $startLiteral -p $patternLiteral -v"
}

Invoke-Stage 'git diff check' {
    Invoke-NativeChecked 'git' @('-C', $RepoRoot, 'diff', '--check', 'HEAD', '--')
}

Invoke-Stage 'focused PHP hybrid tests' {
    Invoke-PhpFiles @(
        'master/tests/linux_hybrid_leigod_schema_test.php',
        'master/tests/seed_leigod_execution_leases_test.php',
        'master/tests/leigod_execution_lease_service_test.php',
        'master/tests/client_leigod_line_lease_projection_test.php',
        'master/tests/client_leigod_execution_lease_broker_test.php',
        'master/tests/client_config_revision_service_test.php',
        'master/tests/client_config_watch_endpoint_test.php',
        'master/tests/node_leigod_line_management_test.php',
        'master/tests/node_leigod_line_health_ingest_test.php',
        'master/tests/leigod_route_node_availability_test.php',
        'master/tests/publish_dedicated_leigod_candidates_test.php',
        'master/tests/leigod_linux_hybrid_contract_test.php',
        'master/tests/leigod_hybrid_sidecar_independence_test.php',
        'master/tests/leigod_linux_hybrid_slave_publish_test.php',
        'master/tests/leigod_pathd_artifact_distribution_test.php'
    )
}

Invoke-Stage 'all tracked PHP lint' {
    $trackedPhp = @(& git -C $RepoRoot ls-files -- '*.php')
    if ($LASTEXITCODE -ne 0 -or $trackedPhp.Count -eq 0) {
        throw 'Unable to enumerate tracked PHP files.'
    }
    $listPath = [System.IO.Path]::GetTempFileName()
    try {
        [System.IO.File]::WriteAllText(
            $listPath,
            (($trackedPhp -join "`n") + "`n"),
            [System.Text.UTF8Encoding]::new($false)
        )
        $wslList = Convert-ToBashLiteral (Convert-ToWslPath $listPath)
        Invoke-WslChecked ('while IFS= read -r php_file; do php -l "$php_file" >/dev/null; done < {0}' -f $wslList)
    }
    finally {
        Remove-Item -LiteralPath $listPath -Force -ErrorAction SilentlyContinue
    }
}

Invoke-Stage 'focused Python suites' {
    Invoke-PythonDiscovery 'client-agent/tests' 'test_client_agent_config_watch.py'
    Invoke-PythonDiscovery 'client-agent/tests' 'test_client_agent_pathd_runtime.py'
    Invoke-PythonDiscovery 'client-agent/tests' 'test_leigod_only_runtime_contract.py'
    Invoke-WslPythonDiscovery 'slave-agent/tests' 'test_leigod_linux_hybrid_runtime.py'
}

Invoke-Stage 'broad client and slave Python suites' {
    Invoke-PythonDiscovery 'client-agent/tests' 'test_*.py'
    Invoke-WslChecked 'for test_file in slave-agent/tests/test_*.py; do test_name=$(basename "$test_file"); if [ "$test_name" = test_leigod_linux_hybrid_integration.py ]; then continue; fi; nice -n 10 python3 -m unittest discover -s slave-agent/tests -p "$test_name" -v; done'
}

Invoke-Stage 'Rust leikwan-bond tests' {
    Invoke-WslChecked 'cd wstunnel-main && nice -n 10 env CARGO_BUILD_JOBS=1 RUSTFLAGS=-Awarnings cargo test -p leikwan-bond -- --test-threads=1'
}

Invoke-Stage 'Rust leikwan-ingress tests' {
    Invoke-WslChecked 'cd wstunnel-main && nice -n 10 env CARGO_BUILD_JOBS=1 RUSTFLAGS=-Awarnings cargo test -p leikwan-ingress -- --test-threads=1'
}

Invoke-Stage 'real-process Linux hybrid integration' {
    Invoke-WslChecked 'nice -n 10 python3 -m unittest slave-agent.tests.test_leigod_linux_hybrid_integration -v'
}

Invoke-Stage 'artifact hash and source mirrors' {
    Invoke-PhpFiles @(
        'master/tests/leigod_pathd_artifact_distribution_test.php',
        'master/tests/artifact_versions_manifest_consistency_test.php',
        'master/tests/artifact_source_build_provenance_guard_test.php'
    )
    Invoke-PythonDiscovery 'client-agent/tests' 'test_client_agent_installer_helper.py'
    Invoke-PythonDiscovery 'client-agent/tests' 'test_artifact_versions_consistency.py'
}

Invoke-Stage 'Windows and Android contract regressions' {
    Invoke-PhpFiles @(
        'master/tests/leigod_hybrid_sidecar_independence_test.php',
        'master/tests/leigod_only_platform_authorization_gate_test.php',
        'master/tests/leigod_only_canary_promotion_gate_test.php',
        'master/tests/artifact_capability_hash_consistency_test.php',
        'master/tests/bond_versions_manifest_regression_test.php',
        'master/tests/windows_client_app_version_manifest_regression_test.php',
        'master/tests/windows_client_manifest_relative_artifact_url_regression_test.php'
    )
}

$totalSeconds = [math]::Round(($Timings | Measure-Object -Property Seconds -Sum).Sum, 1)
Write-Host "[SUMMARY] $($Timings.Count) stages passed in ${totalSeconds}s"
foreach ($timing in $Timings) {
    Write-Host ("  {0,7:N1}s  {1}" -f $timing.Seconds, $timing.Name)
}
