using System.Diagnostics;
using System.Text;

namespace LeikwanBridge.Headless;

// Service registration goes through sc.exe on purpose: no extra dependency, and
// every setting the operator sees in services.msc is one an operator can also
// read back with "sc qc".
internal static class WindowsServiceInstaller
{
    public const string DefaultServiceName = "LeikwanBridgeHeadless";

    public const string DisplayName = "LeiKwan Bridge (no GUI)";

    /// <summary>
    /// Register the service, or bring an existing registration up to date
    /// (binPath/arguments, LocalSystem, automatic start, recovery actions) so
    /// re-running the installer never fails with "service exists" (1073).
    /// </summary>
    public static int Install(string serviceName, string[] clientArguments, bool startAfterInstall = true)
    {
        var executable = Environment.ProcessPath;
        if (string.IsNullOrWhiteSpace(executable))
        {
            Console.Error.WriteLine("cannot resolve the current executable path");
            return 1;
        }

        var commandLine = BuildCommandLine(executable, clientArguments);
        var query = Capture("sc.exe", "query", serviceName);
        var exists = query.ExitCode == 0;
        var wasRunning = exists && query.Output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase);
        int registration;
        if (exists)
        {
            registration = Configure(serviceName, commandLine);
        }
        else
        {
            registration = Run("sc.exe", "create", serviceName, "binPath=", commandLine, "start=", "auto", "obj=", "LocalSystem",
                "DisplayName=", DisplayName);
            if (registration == ServiceExistsExitCode)
            {
                // Raced with another registration: update instead of failing.
                exists = true;
                registration = Configure(serviceName, commandLine);
            }
        }

        if (registration != 0)
            return registration;

        Run("sc.exe", "description", serviceName, "LeiKwan Bridge windowless client: keeps this machine's panel forwards running without a desktop session.");
        Run("sc.exe", "failure", serviceName, "reset=", "86400", "actions=", "restart/5000/restart/10000/restart/30000");
        Run("sc.exe", "failureflag", serviceName, "1");
        Console.WriteLine(exists
            ? $"updated service {serviceName} registration"
            : $"created service {serviceName}");
        if (!startAfterInstall)
            return 0;

        if (wasRunning)
        {
            // A changed binPath only takes effect on the next start.
            Run("sc.exe", "stop", serviceName);
            WaitForState(serviceName, "STOPPED", TimeSpan.FromSeconds(60));
        }

        var start = Run("sc.exe", "start", serviceName);
        Console.WriteLine(start == 0
            ? $"started service {serviceName}"
            : $"service {serviceName} is registered; start it manually with: sc start {serviceName}");
        return 0;
    }

    private const int ServiceExistsExitCode = 1073;

    internal static string BuildCommandLine(string executable, IEnumerable<string> clientArguments)
    {
        var commandLine = new StringBuilder();
        commandLine.Append('"').Append(executable).Append('"').Append(" --headless --service");
        foreach (var argument in clientArguments)
        {
            commandLine.Append(' ');
            if (argument.Contains(' '))
                commandLine.Append('"').Append(argument).Append('"');
            else
                commandLine.Append(argument);
        }

        return commandLine.ToString();
    }

    private static int Configure(string serviceName, string commandLine)
        => Run("sc.exe", "config", serviceName, "binPath=", commandLine, "start=", "auto", "obj=", "LocalSystem",
            "DisplayName=", DisplayName);

    private static void WaitForState(string serviceName, string state, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            var query = Capture("sc.exe", "query", serviceName);
            if (query.ExitCode != 0 || query.Output.Contains(state, StringComparison.OrdinalIgnoreCase))
                return; // reached, or the service does not exist (any more)
            Thread.Sleep(1000);
        }
    }

    public static int Uninstall(string serviceName)
    {
        Run("sc.exe", "stop", serviceName);
        WaitForState(serviceName, "STOPPED", TimeSpan.FromSeconds(60));
        var delete = Run("sc.exe", "delete", serviceName);
        if (delete == 0)
            Console.WriteLine($"removed service {serviceName}");
        return delete;
    }

    private static (int ExitCode, string Output) Capture(string fileName, params string[] arguments)
    {
        var startInfo = new ProcessStartInfo
        {
            FileName = fileName,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments)
            startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo);
        if (process is null)
            return (1, string.Empty);
        var output = process.StandardOutput.ReadToEnd() + process.StandardError.ReadToEnd();
        process.WaitForExit(30000);
        return (process.ExitCode, output);
    }

    private static int Run(string fileName, params string[] arguments)
    {
        var startInfo = new ProcessStartInfo
        {
            FileName = fileName,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments)
            startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo);
        if (process is null)
        {
            Console.Error.WriteLine($"could not start {fileName}");
            return 1;
        }

        var output = process.StandardOutput.ReadToEnd() + process.StandardError.ReadToEnd();
        process.WaitForExit(30000);
        if (process.ExitCode != 0 && !string.IsNullOrWhiteSpace(output))
            Console.Error.WriteLine(output.Trim());
        return process.ExitCode;
    }
}
