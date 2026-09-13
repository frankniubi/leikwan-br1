using System.Diagnostics;
using System.Text;

namespace LeikwanBridge.Headless;

// Service registration goes through sc.exe on purpose: no extra dependency, and
// every setting the operator sees in services.msc is one an operator can also
// read back with "sc qc".
internal static class WindowsServiceInstaller
{
    public const string DefaultServiceName = "LeikwanBridgeHeadless";

    public static int Install(string serviceName, string[] clientArguments)
    {
        var executable = Environment.ProcessPath;
        if (string.IsNullOrWhiteSpace(executable))
        {
            Console.Error.WriteLine("cannot resolve the current executable path");
            return 1;
        }

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

        var create = Run("sc.exe", "create", serviceName, "binPath=", commandLine.ToString(), "start=", "auto", "obj=", "LocalSystem",
            "DisplayName=", "LeiKwan Bridge (no GUI)");
        if (create != 0)
            return create;

        Run("sc.exe", "description", serviceName, "LeiKwan Bridge windowless client: keeps this machine's panel forwards running without a desktop session.");
        Run("sc.exe", "failure", serviceName, "reset=", "86400", "actions=", "restart/5000/restart/10000/restart/30000");
        Run("sc.exe", "failureflag", serviceName, "1");
        var start = Run("sc.exe", "start", serviceName);
        Console.WriteLine(start == 0
            ? $"installed and started service {serviceName}"
            : $"installed service {serviceName}; start it manually with: sc start {serviceName}");
        return 0;
    }

    public static int Uninstall(string serviceName)
    {
        Run("sc.exe", "stop", serviceName);
        var delete = Run("sc.exe", "delete", serviceName);
        if (delete == 0)
            Console.WriteLine($"removed service {serviceName}");
        return delete;
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
