using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsNetchModeImporterTests
{
    [Fact]
    public void LoadImportsTextAndProcessModeJson()
    {
        var root = Path.Combine(Path.GetTempPath(), "netch-modes-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path.Combine(root, "Game"));
        try
        {
            File.WriteAllText(Path.Combine(root, "Game", "Demo.txt"), "# Demo, 0\r\nDemo.exe\r\n^DemoLauncher\\.exe$\r\n");
            File.WriteAllText(Path.Combine(root, "Game", "Json Game.json"), """
                {"type":"ProcessMode","remark":{"zh-CN":"JSON 游戏"},"handle":["JsonGame.exe"]}
                """);

            var profiles = WindowsNetchModeImporter.Load(root);

            Assert.Equal(2, profiles.Count);
            Assert.Contains(profiles, profile => profile.DisplayName == "Demo"
                && profile.ProcessPatterns.Contains("Demo.exe")
                && profile.PatternsAreRegex);
            Assert.Contains(profiles, profile => profile.DisplayName == "JSON 游戏"
                && profile.ProcessPatterns.Contains("JsonGame.exe"));
        }
        finally
        {
            try { Directory.Delete(root, recursive: true); } catch (IOException) { }
        }
    }

    [Fact]
    public void LoadSkipsGlobalModeAndEmptyFiles()
    {
        var root = Path.Combine(Path.GetTempPath(), "netch-modes-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        try
        {
            File.WriteAllText(Path.Combine(root, "Global.json"), "{\"type\":\"ProcessMode\",\"handle\":[\".*\"]}");
            File.WriteAllText(Path.Combine(root, "Empty.txt"), "# empty\r\n");

            Assert.Empty(WindowsNetchModeImporter.Load(root));
        }
        finally
        {
            try { Directory.Delete(root, recursive: true); } catch (IOException) { }
        }
    }

    [Fact]
    public void LoadSkipsTunTapIpListsAndIncludeDirectives()
    {
        var root = Path.Combine(Path.GetTempPath(), "netch-modes-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path.Combine(root, "TUNTAP"));
        try
        {
            File.WriteAllText(Path.Combine(root, "TUNTAP", "Demo.txt"), "# Demo\r\n1.1.1.0/24\r\n");
            File.WriteAllText(Path.Combine(root, "Demo routes.txt"), "# Demo routes\r\n#include <Bypass LAN.h>\r\n2.2.2.0/24\r\n");

            Assert.Empty(WindowsNetchModeImporter.Load(root));
        }
        finally
        {
            try { Directory.Delete(root, recursive: true); } catch (IOException) { }
        }
    }
}
