using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Xml;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public sealed class MacAutostartService : IAutostartService
{
    public const string Label = "com.leikwan.bridge";

    private readonly MacPlatformPaths _paths;
    private readonly IMacCommandRunner _runner;
    private readonly Func<int> _userId;

    public MacAutostartService(
        MacPlatformPaths paths,
        IMacCommandRunner? runner = null,
        Func<int>? userId = null)
    {
        _paths = paths;
        _runner = runner ?? new MacCommandRunner();
        _userId = userId ?? (() => checked((int)geteuid()));
        PlistPath = paths.HomeDirectory.TrimEnd('/', '\\') + "/Library/LaunchAgents/" + Label + ".plist";
    }

    public string PlistPath { get; }

    public Task<bool> IsEnabledAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        return Task.FromResult(File.Exists(PlistPath));
    }

    public async Task SetEnabledAsync(bool enabled, CancellationToken ct)
    {
        var domain = $"gui/{_userId()}";
        if (!enabled)
        {
            await RunLaunchctlAsync(["bootout", $"{domain}/{Label}"], ct, requireSuccess: false);
            try
            {
                File.Delete(PlistPath);
            }
            catch (IOException)
            {
            }
            return;
        }

        WritePlist();
        await RunLaunchctlAsync(["bootout", $"{domain}/{Label}"], ct, requireSuccess: false);
        await RunLaunchctlAsync(["bootstrap", domain, PlistPath], ct, requireSuccess: true);
    }

    private void WritePlist()
    {
        var directory = Path.GetDirectoryName(PlistPath)
            ?? throw new InvalidOperationException("launch_agent_path_invalid");
        Directory.CreateDirectory(directory);
        SetPrivateDirectoryMode(directory);
        var temporary = PlistPath + "." + Guid.NewGuid().ToString("N") + ".tmp";
        var settings = new XmlWriterSettings
        {
            Encoding = new System.Text.UTF8Encoding(encoderShouldEmitUTF8Identifier: false),
            Indent = true,
            OmitXmlDeclaration = false,
        };
        using (var writer = XmlWriter.Create(temporary, settings))
        {
            writer.WriteStartDocument();
            writer.WriteDocType("plist", "-//Apple//DTD PLIST 1.0//EN", "http://www.apple.com/DTDs/PropertyList-1.0.dtd", null);
            writer.WriteStartElement("plist");
            writer.WriteAttributeString("version", "1.0");
            writer.WriteStartElement("dict");
            WriteString(writer, "Label", Label);
            writer.WriteElementString("key", "ProgramArguments");
            writer.WriteStartElement("array");
            writer.WriteElementString("string", _paths.BundleDirectory.TrimEnd('/', '\\') + "/Contents/MacOS/LeikwanBridge.Mac");
            writer.WriteElementString("string", "--background");
            writer.WriteEndElement();
            WriteBool(writer, "RunAtLoad", true);
            WriteBool(writer, "KeepAlive", false);
            WriteString(writer, "StandardOutPath", _paths.LogDirectory.TrimEnd('/', '\\') + "/launch-agent.out.log");
            WriteString(writer, "StandardErrorPath", _paths.LogDirectory.TrimEnd('/', '\\') + "/launch-agent.err.log");
            writer.WriteEndElement();
            writer.WriteEndElement();
            writer.WriteEndDocument();
        }
        SetPrivateFileMode(temporary);
        File.Move(temporary, PlistPath, overwrite: true);
        SetPrivateFileMode(PlistPath);
    }

    private async Task RunLaunchctlAsync(IReadOnlyList<string> arguments, CancellationToken ct, bool requireSuccess)
    {
        var startInfo = new ProcessStartInfo
        {
            FileName = "/bin/launchctl",
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments)
            startInfo.ArgumentList.Add(argument);
        var result = await _runner.RunAsync(startInfo, ct);
        if (requireSuccess && result.ExitCode != 0)
            throw new InvalidOperationException("launch_agent_update_failed");
    }

    private static void WriteString(XmlWriter writer, string key, string value)
    {
        writer.WriteElementString("key", key);
        writer.WriteElementString("string", value);
    }

    private static void WriteBool(XmlWriter writer, string key, bool value)
    {
        writer.WriteElementString("key", key);
        writer.WriteStartElement(value ? "true" : "false");
        writer.WriteEndElement();
    }

    private static void SetPrivateDirectoryMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }

    private static void SetPrivateFileMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
    }

    [DllImport("libSystem.B.dylib")]
    private static extern uint geteuid();
}
