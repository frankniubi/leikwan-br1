using System.Text.RegularExpressions;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// Guards the rule that panel/update HTTP clients come only from
/// LeikwanHttpClientFactory (UseProxy=false plus physical-NIC binding). A bare
/// HttpClient/HttpClientHandler honours the system proxy and any TUN route,
/// which is how the GUI, the headless clients or `leikwan update` would leak
/// sign-in, heartbeat or update traffic into a third-party tunnel.
/// </summary>
public sealed class PanelHttpClientConstructionTests
{
    private static readonly string[] AllowedFiles =
    [
        // The sanctioned factory itself.
        Path.Combine("LeikwanBridge.Core", "Services", "LeikwanHttpClientFactory.cs"),
        // DoH bootstrap: UseProxy=false and a socket-binding connect callback.
        Path.Combine("LeikwanBridge.Core", "Services", "StrictDohResolver.cs"),
    ];

    private static readonly Regex RawConstruction = new(
        @"new\s+(HttpClient|HttpClientHandler|SocketsHttpHandler|WebClient)\s*[\(\{]"
        + @"|\bHttpClient\??\s+\w+\s*=\s*new\s*\("
        + @"|WebRequest\.Create",
        RegexOptions.Compiled);

    [Fact]
    public void NoClientProjectBuildsItsOwnHttpClient()
    {
        var src = FindSourceRoot();
        var offenders = new List<string>();
        foreach (var project in new[] { "LeikwanBridge.Core", "LeikwanBridge.Headless", "LeikwanBridge.App", "LeikwanBridge.Mac" })
        {
            var projectRoot = Path.Combine(src, project);
            Assert.True(Directory.Exists(projectRoot), projectRoot);
            foreach (var file in Directory.EnumerateFiles(projectRoot, "*.cs", SearchOption.AllDirectories))
            {
                var relative = Path.GetRelativePath(src, file);
                if (IsBuildOutput(relative) || AllowedFiles.Contains(relative, StringComparer.OrdinalIgnoreCase))
                    continue;
                var lines = File.ReadAllLines(file);
                for (var index = 0; index < lines.Length; index++)
                {
                    var line = lines[index].TrimStart();
                    if (line.StartsWith("//", StringComparison.Ordinal) || line.StartsWith("///", StringComparison.Ordinal))
                        continue;
                    if (RawConstruction.IsMatch(line))
                        offenders.Add($"{relative}:{index + 1}: {line}");
                }
            }
        }

        Assert.True(offenders.Count == 0, "Use LeikwanHttpClientFactory instead of:\n" + string.Join("\n", offenders));
    }

    private static bool IsBuildOutput(string relative)
    {
        var segments = relative.Split(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        return segments.Any(segment => segment is "bin" or "obj");
    }

    private static string FindSourceRoot()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.Core")))
            root = root.Parent;
        Assert.NotNull(root);
        return Path.Combine(root.FullName, "src");
    }
}
