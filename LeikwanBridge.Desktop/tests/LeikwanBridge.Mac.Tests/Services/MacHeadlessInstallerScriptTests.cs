using System.Diagnostics;
using System.Text.RegularExpressions;
using System.Xml.Linq;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

/// <summary>
/// Static checks of install/install-macos-headless.sh: it must parse, and it
/// must keep the launchd supervision and the idempotent upgrade/rollback
/// contract. The real bootstrap/bootout behaviour can only be exercised on a Mac.
/// </summary>
public sealed class MacHeadlessInstallerScriptTests
{
    [Theory]
    [InlineData("install-macos-headless.sh")]
    [InlineData("uninstall-macos-headless.sh")]
    public void Script_ParsesWithBashAndPosixSh(string name)
    {
        var path = ScriptPath(name);
        foreach (var shell in new[] { "bash", "dash", "sh" })
        {
            var executable = FindOnPath(shell);
            if (executable is null)
                continue;
            var start = new ProcessStartInfo(executable)
            {
                RedirectStandardError = true,
                RedirectStandardOutput = true,
                UseShellExecute = false,
            };
            start.ArgumentList.Add("-n");
            start.ArgumentList.Add(path);
            using var process = Process.Start(start)!;
            var error = process.StandardError.ReadToEnd();
            Assert.True(process.WaitForExit(10000), $"{shell} -n timed out");
            Assert.True(process.ExitCode == 0, $"{shell} -n {name} failed: {error}");
        }
    }

    [Fact]
    public void Installer_DeclaresLaunchdSupervision()
    {
        var script = File.ReadAllText(ScriptPath("install-macos-headless.sh"));
        foreach (var needle in new[]
        {
            "<key>RunAtLoad</key>",
            "<key>KeepAlive</key>",
            "<key>SuccessfulExit</key>",
            "<key>ThrottleInterval</key>",
            "<key>AbandonProcessGroup</key>",
            "<key>ExitTimeOut</key>",
            "/Library/Logs/Leikwan/ClientAgent",
        })
        {
            Assert.Contains(needle, script, StringComparison.Ordinal);
        }
        // Logs live in a persistent directory, never under /tmp.
        var start = script.IndexOf("cat <<PLIST", StringComparison.Ordinal);
        var end = script.IndexOf("\nPLIST\n", start, StringComparison.Ordinal);
        Assert.True(start >= 0 && end > start);
        Assert.DoesNotContain("/tmp", script[start..end], StringComparison.Ordinal);
    }

    [Fact]
    public void Installer_UpgradesIdempotentlyWithRollback()
    {
        var script = File.ReadAllText(ScriptPath("install-macos-headless.sh"));
        foreach (var needle in new[]
        {
            "wait_unloaded",   // bootout is waited for before bootstrap
            "bootstrap_service",
            "verify_running",  // the new daemon must stay up
            "rollback",
            "PLIST_BACKUP",
            "OLD_APP_BACKUP",
            "CHANGES_STARTED",
            "INSTALL_COMMITTED=1",
        })
        {
            Assert.Contains(needle, script, StringComparison.Ordinal);
        }
        // kickstart -k would kill the instance RunAtLoad just started.
        Assert.DoesNotContain("kickstart -k \"system/$LABEL\"\n", script, StringComparison.Ordinal);
    }

    [Fact]
    public void LaunchDaemonPlistRaisesTheOpenFileLimitAndKeepsKeepAlive()
    {
        var script = File.ReadAllText(ScriptPath("install-macos-headless.sh")).Replace("\r\n", "\n");
        const string opening = "cat <<PLIST\n";
        var start = script.IndexOf(opening, StringComparison.Ordinal);
        var end = script.IndexOf("\nPLIST\n", start, StringComparison.Ordinal);
        Assert.True(start >= 0 && end > start);
        // Render the heredoc with placeholder values and read it as a plist.
        var body = Regex.Replace(script[(start + opening.Length)..end], @"\$[A-Za-z_]+", "X");
        var dict = XDocument.Parse(body).Root!.Element("dict")!;

        foreach (var limits in new[] { "SoftResourceLimits", "HardResourceLimits" })
        {
            var files = ValueOf(ValueOf(dict, limits), "NumberOfFiles");
            Assert.Equal("integer", files.Name.LocalName);
            Assert.Equal("65536", files.Value);
        }

        Assert.Equal("false", ValueOf(ValueOf(dict, "KeepAlive"), "SuccessfulExit").Name.LocalName);
        Assert.Equal("true", ValueOf(dict, "RunAtLoad").Name.LocalName);
        Assert.Equal("false", ValueOf(dict, "AbandonProcessGroup").Name.LocalName);
    }

    private static XElement ValueOf(XElement container, string key)
    {
        var items = container.Elements().ToList();
        var index = items.FindIndex(element => element.Name.LocalName == "key" && element.Value == key);
        Assert.True(index >= 0 && index + 1 < items.Count, $"plist key {key} missing");
        return items[index + 1];
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
        throw new FileNotFoundException("installer script not found above the test directory", name);
    }

    private static string? FindOnPath(string name)
    {
        var suffixes = OperatingSystem.IsWindows() ? new[] { ".exe", "" } : new[] { "" };
        foreach (var directory in (Environment.GetEnvironmentVariable("PATH") ?? string.Empty).Split(Path.PathSeparator))
        {
            if (string.IsNullOrWhiteSpace(directory))
                continue;
            // System32\bash.exe is the WSL launcher, which cannot read a Windows path.
            if (OperatingSystem.IsWindows()
                && directory.Contains("System32", StringComparison.OrdinalIgnoreCase))
                continue;
            foreach (var suffix in suffixes)
            {
                var candidate = Path.Combine(directory, name + suffix);
                if (File.Exists(candidate))
                    return candidate;
            }
        }
        return null;
    }
}
