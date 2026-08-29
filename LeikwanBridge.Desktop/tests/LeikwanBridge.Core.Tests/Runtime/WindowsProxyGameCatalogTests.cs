using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyGameCatalogTests
{
    [Fact]
    public void BuildOptionsMarksMatchingGameAsRunningWithoutExposingArbitraryProcesses()
    {
        var options = WindowsProxyGameCatalog.BuildOptions(["VALORANT-Win64-Shipping.exe", "not-a-game.exe"]);

        Assert.Contains(options, option => option.Id == "valorant" && option.IsRunning);
        Assert.DoesNotContain(options, option => option.DisplayName.Contains("not-a-game", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public void FindReturnsAllProcessesForSelectedGame()
    {
        var game = WindowsProxyGameCatalog.Find("genshin-impact");

        Assert.NotNull(game);
        Assert.Contains("GenshinImpact.exe", game!.ProcessNames);
        Assert.Contains("YuanShen.exe", game.ProcessNames);
    }

    [Fact]
    public void CounterStrikeIncludesSteamNetworkingProcesses()
    {
        var game = WindowsProxyGameCatalog.Find("counter-strike-2");

        Assert.NotNull(game);
        Assert.Contains("cs2.exe", game!.ProcessNames);
        Assert.Contains("steam.exe", game.ProcessNames);
        Assert.Contains("steamwebhelper.exe", game.ProcessNames);
        Assert.Contains("steamservice.exe", game.ProcessNames);
        Assert.Contains("GameOverlayUI.exe", game.ProcessNames);
        Assert.Contains("steam_monitor.exe", game.ProcessNames);
        Assert.Contains("x64launcher.exe", game.ProcessNames);
        Assert.Contains("streaming_client.exe", game.ProcessNames);
    }

    [Fact]
    public void UnknownGameIdDoesNotProduceRules()
    {
        Assert.Null(WindowsProxyGameCatalog.Find("unknown-game"));
    }

    [Fact]
    public void BuildOptionsIncludesImportedProfilesAndMatchesRegexProcesses()
    {
        var profile = new WindowsProxyGameProfile
        {
            Id = "netch:test",
            DisplayName = "My game",
            ProcessPatterns = ["^MyGame\\.exe$"],
            PatternsAreRegex = true,
            Source = "netch",
        };

        var options = WindowsProxyGameCatalog.BuildOptions(["MyGame.exe"], [profile]);
        var option = Assert.Single(options, item => item.Id == "netch:test");

        Assert.True(option.IsCustom);
        Assert.True(option.IsRunning);
        Assert.True(option.PatternsAreRegex);
    }
}
