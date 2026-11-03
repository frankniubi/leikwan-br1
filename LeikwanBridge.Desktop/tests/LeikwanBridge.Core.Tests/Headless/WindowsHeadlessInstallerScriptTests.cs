using System.Diagnostics;
using Xunit;

namespace LeikwanBridge.Core.Tests.Headless;

/// <summary>
/// Static checks of the Windows headless installer/uninstaller: they must parse
/// (PowerShell's own parser, no Pester needed) and keep the idempotent-upgrade
/// structure that D4 depended on.
/// </summary>
public sealed class WindowsHeadlessInstallerScriptTests
{
    [Theory]
    [InlineData("install-windows-headless.ps1")]
    [InlineData("uninstall-windows-headless.ps1")]
    public void ScriptParsesWithoutErrors(string name)
    {
        if (!OperatingSystem.IsWindows())
            return;
        var path = ScriptPath(name);
        var command =
            "$tokens = $null; $errors = $null; "
            + "[void][System.Management.Automation.Language.Parser]::ParseFile('" + path.Replace("'", "''") + "', [ref]$tokens, [ref]$errors); "
            + "foreach ($e in $errors) { Write-Output (\"{0}:{1} {2}\" -f $e.Extent.StartLineNumber, $e.Extent.StartColumnNumber, $e.Message) }; "
            + "exit $errors.Count";
        var startInfo = new ProcessStartInfo("powershell.exe")
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", command })
            startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo)!;
        var output = process.StandardOutput.ReadToEnd() + process.StandardError.ReadToEnd();
        Assert.True(process.WaitForExit(60000), "powershell.exe did not finish");
        Assert.True(process.ExitCode == 0, $"{name} has parse errors:\n{output}");
    }

    [Fact]
    public void InstallerStopsBacksUpVerifiesAndRollsBack()
    {
        var script = File.ReadAllText(ScriptPath("install-windows-headless.ps1"));

        // Stop + drain before any file is copied.
        var stop = script.IndexOf("Stop-HeadlessService -Name $ServiceName -Directory $InstallDir", StringComparison.Ordinal);
        var copy = script.IndexOf("Invoke-Robocopy $PayloadDir $InstallDir", StringComparison.Ordinal);
        Assert.True(stop > 0 && copy > stop, "the service must be stopped before the payload is copied");
        Assert.Contains("Get-InstallDirProcesses", script);

        // Backup, keep-3 pruning, admin-only ACL on the backup root.
        Assert.Contains("backups", script);
        Assert.Contains("$KeepBackups = 3", script);
        Assert.Contains("*S-1-5-18:(OI)(CI)F", script);

        // Register-or-update without starting, then start + health gate.
        Assert.Contains("\"--install-service\"", script);
        Assert.Contains("\"--no-start\"", script);
        Assert.Contains("Wait-HeadlessHealthy", script);
        Assert.Contains("headless-status.json", script);

        // Rollback restores files, token and registration, then restarts.
        Assert.Contains("'/MIR'", script);
        Assert.Contains("$previousToken", script);
        Assert.Contains("Set-ServiceImagePath", script);

        // Token handling unchanged (panel test guards these too).
        Assert.Contains("Set-Content -LiteralPath $tokenPath", script);
        Assert.Contains("--agent-token-file", script);
        Assert.DoesNotContain("ConvertTo-SecureString", script);
    }

    /// <summary>
    /// Runs the installer's own backup / copy / restore / process-scan
    /// functions (lifted out of the script by the PowerShell AST, under the
    /// script's StrictMode) against temp directories. No service, no admin.
    /// </summary>
    [Fact]
    public void InstallerBackupCopyAndRestoreFunctionsWork()
    {
        if (!OperatingSystem.IsWindows())
            return;
        var root = Path.Combine(Path.GetTempPath(), "lkb-ps-" + Guid.NewGuid().ToString("N"));
        var harness = Path.Combine(Path.GetTempPath(), "lkb-ps-harness-" + Guid.NewGuid().ToString("N") + ".ps1");
        try
        {
            File.WriteAllText(harness, """
                param([string]$Script, [string]$Root)
                Set-StrictMode -Version Latest
                $ErrorActionPreference = "Stop"
                $tokens = $null; $errors = $null
                $ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
                foreach ($f in $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false)) {
                    if ($f.Name -ne 'Protect-AdminOnlyDirectory') { . ([scriptblock]::Create($f.Extent.Text)) }
                }
                # The real one locks the directory to SYSTEM/Administrators.
                function Protect-AdminOnlyDirectory([string]$Path) { New-Item -ItemType Directory -Force -Path $Path | Out-Null }
                $install = Join-Path $Root 'install'
                $payload = Join-Path $Root 'payload'
                $backupRoot = Join-Path $Root 'backups'
                $KeepBackups = 3
                New-Item -ItemType Directory -Force -Path (Join-Path $install 'bin'), (Join-Path $payload 'bin') | Out-Null
                Set-Content (Join-Path $install 'leikwan-bridge-headless.exe') 'old'
                Set-Content (Join-Path $install 'bin\ultra-client.exe') 'old-rt'
                Set-Content (Join-Path $payload 'leikwan-bridge-headless.exe') 'new'
                Set-Content (Join-Path $payload 'bin\extra.dll') 'extra'
                Set-Content (Join-Path $payload 'install-windows-headless.ps1') 'script'
                for ($i = 0; $i -lt 5; $i++) {
                    $b = New-InstallBackup -Directory $install -Version "0.1.20.5$i"
                    (Get-Item -LiteralPath $b).CreationTimeUtc = [datetime]::UtcNow.AddMinutes(-10 + $i)
                    Start-Sleep -Milliseconds 1100
                }
                "backups=" + @(Get-ChildItem -LiteralPath $backupRoot -Directory).Count
                Invoke-Robocopy $payload $install @('/E', '/IS', '/IT', '/XF', 'install-windows-headless.ps1')
                "copied=" + (Get-Content (Join-Path $install 'leikwan-bridge-headless.exe')) + "," + (Test-Path (Join-Path $install 'bin\extra.dll')) + "," + (Test-Path (Join-Path $install 'install-windows-headless.ps1'))
                $latest = Get-ChildItem -LiteralPath $backupRoot -Directory | Sort-Object CreationTimeUtc -Descending | Select-Object -First 1
                Invoke-Robocopy $latest.FullName $install @('/MIR', '/IS', '/IT')
                "restored=" + (Get-Content (Join-Path $install 'leikwan-bridge-headless.exe')) + "," + (Test-Path (Join-Path $install 'bin\extra.dll')) + "," + (Get-Content (Join-Path $install 'bin\ultra-client.exe'))
                "procs=" + @(Get-InstallDirProcesses $install).Count
                "utc=" + (Read-StatusUpdatedUtc ([pscustomobject]@{ UpdatedAt = '2026-09-24T10:00:00+08:00' })).ToString('o')
                Stop-HeadlessService -Name 'LkbHarnessMissingService' -Directory $install -DrainTimeoutSeconds 2
                "stopped=ok"
                """);
            var output = RunPowerShell(["-File", harness, "-Script", ScriptPath("install-windows-headless.ps1"), "-Root", root], out var exitCode);

            Assert.True(exitCode == 0, output);
            Assert.Contains("backups=3", output);
            Assert.Contains("copied=new,True,False", output);
            Assert.Contains("restored=old,False,old-rt", output);
            Assert.Contains("procs=0", output);
            Assert.Contains("utc=2026-09-24T02:00:00.0000000Z", output);
            Assert.Contains("stopped=ok", output);
        }
        finally
        {
            File.Delete(harness);
            if (Directory.Exists(root))
                Directory.Delete(root, recursive: true);
        }
    }

    private static string RunPowerShell(IEnumerable<string> arguments, out int exitCode)
    {
        var startInfo = new ProcessStartInfo("powershell.exe")
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass" }.Concat(arguments))
            startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo)!;
        var stderr = process.StandardError.ReadToEndAsync();
        var output = process.StandardOutput.ReadToEnd();
        Assert.True(process.WaitForExit(120000), "powershell.exe did not finish");
        exitCode = process.ExitCode;
        return output + stderr.Result;
    }

    private static string ScriptPath(string name)
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, "install", name);
            if (File.Exists(candidate))
                return candidate;
            directory = directory.Parent;
        }

        throw new FileNotFoundException("install script not found above the test output directory", name);
    }
}
