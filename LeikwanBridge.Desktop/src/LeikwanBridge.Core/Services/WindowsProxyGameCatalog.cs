using System.Text.RegularExpressions;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Built-in game profiles plus profiles imported from Netch's mode directory.
/// Process mode deliberately uses profiles instead of exposing an arbitrary
/// process list, so selecting a game remains a predictable one-click action.
/// </summary>
public static class WindowsProxyGameCatalog
{
    public static IReadOnlyList<WindowsProxyGameDefinition> Definitions { get; } =
    [
        new("steam", "Steam", ["steam.exe", "steamwebhelper.exe"]),
        new("league-of-legends", "League of Legends", ["LeagueClient.exe", "LeagueClientUx.exe", "LeagueClientUxRender.exe", "League of Legends.exe", "RiotClientServices.exe"]),
        new("valorant", "VALORANT", ["VALORANT-Win64-Shipping.exe", "RiotClientServices.exe"]),
        new("overwatch-2", "Overwatch 2", ["Overwatch.exe", "Overwatch Launcher.exe", "Agent.exe"]),
        new("genshin-impact", "原神 / Genshin Impact", ["GenshinImpact.exe", "YuanShen.exe"]),
        new("honkai-star-rail", "崩坏：星穹铁道 / Honkai: Star Rail", ["StarRail.exe", "StarRailBase.exe"]),
        new("zenless-zone-zero", "绝区零 / Zenless Zone Zero", ["ZenlessZoneZero.exe"]),
        new("wuthering-waves", "鸣潮 / Wuthering Waves", ["Client-Win64-Shipping.exe", "WutheringWaves.exe"]),
        new("minecraft-java", "Minecraft Java", ["javaw.exe", "MinecraftLauncher.exe"]),
        new("minecraft-bedrock", "Minecraft Bedrock", ["Minecraft.Windows.exe"]),
        new("osu", "osu!", ["osu!.exe"]),
        new("escape-from-tarkov", "Escape from Tarkov", ["EscapeFromTarkov.exe", "EscapeFromTarkov_BE.exe"]),
        new("grand-theft-auto-v", "Grand Theft Auto V", ["GTA5.exe", "PlayGTAV.exe", "RockstarService.exe"]),
        new("counter-strike-2", "Counter-Strike 2", [
            "cs2.exe",
            "html5app_steam.exe",
            "steam.exe",
            "steamwebhelper.exe",
            "gldriverquery.exe",
            "gldriverquery64.exe",
            "secure_desktop_capture.exe",
            "steamservice.exe",
            "steam_monitor.exe",
            "x64launcher.exe",
            "x86launcher.exe",
            "GameOverlayUI.exe",
            "steamerrorreporter.exe",
            "steamerrorreporter64.exe",
            "streaming_client.exe",
            "WriteMiniDump.exe",
        ]),
        new("dota-2", "Dota 2", ["dota2.exe", "steam.exe"]),
        new("apex-legends", "Apex Legends", ["r5apex.exe"]),
        new("pubg", "PUBG: BATTLEGROUNDS", ["TslGame.exe", "TslGame_BE.exe"]),
        new("fortnite", "Fortnite", ["FortniteClient-Win64-Shipping.exe", "FortniteLauncher.exe"]),
        new("warframe", "Warframe", ["Warframe.x64.exe", "Launcher.exe"]),
        new("world-of-warships", "World of Warships", ["worldofwarships.exe"]),
        new("vrchat", "VRChat", ["VRChat.exe"]),
        new("eve-online", "EVE Online", ["exefile.exe", "eve.exe"]),
        new("halo-mcc", "Halo: The Master Chief Collection", ["MCC-Win64-Shipping.exe", "mcclauncher.exe"]),
        new("forza-horizon-4", "Forza Horizon 4", ["ForzaHorizon4.exe"]),
        new("age-of-empires-iv", "Age of Empires IV", ["RelicCardinal_ws.exe"]),
        new("call-of-duty", "Call of Duty", ["cod.exe", "ModernWarfare.exe", "ModernWarfareLauncher.exe"]),
        new("battle-net", "Battle.net", ["Battle.net.exe", "Agent.exe"]),
        new("ea-desktop", "EA Desktop", ["EADesktop.exe", "EALauncher.exe"]),
        new("discord", "Discord", ["Discord.exe", "Update.exe"]),
    ];

    public static WindowsProxyGameDefinition? Find(
        string? id,
        IEnumerable<WindowsProxyGameProfile>? customProfiles = null)
    {
        if (string.IsNullOrWhiteSpace(id))
            return null;

        var normalizedId = id.Trim();
        var custom = customProfiles?.FirstOrDefault(profile =>
            string.Equals(profile.Id, normalizedId, StringComparison.OrdinalIgnoreCase));
        return custom is null
            ? Definitions.FirstOrDefault(item => string.Equals(item.Id, normalizedId, StringComparison.OrdinalIgnoreCase))
            : FromProfile(custom);
    }

    public static IReadOnlyList<WindowsProxyGameOption> BuildOptions(
        IEnumerable<string>? runningProcessNames = null,
        IEnumerable<WindowsProxyGameProfile>? customProfiles = null)
    {
        var running = (runningProcessNames ?? [])
            .Select(name => name?.Trim() ?? string.Empty)
            .Where(name => name.Length > 0)
            .ToList();

        var definitions = Definitions
            .Concat((customProfiles ?? [])
                .Where(profile => profile is not null)
                .Select(FromProfile))
            .GroupBy(item => item.Id, StringComparer.OrdinalIgnoreCase)
            .Select(group => group.Last())
            .OrderBy(item => item.IsCustom)
            .ThenBy(item => item.DisplayName, StringComparer.OrdinalIgnoreCase);

        return definitions
            .Select(definition => new WindowsProxyGameOption(
                definition.Id,
                definition.DisplayName,
                definition.ProcessNames,
                IsRunning(definition, running),
                definition.PatternsAreRegex,
                definition.IsCustom,
                definition.Source))
            .ToList();
    }

    public static WindowsProxyGameDefinition FromProfile(WindowsProxyGameProfile profile)
    {
        ArgumentNullException.ThrowIfNull(profile);
        return new WindowsProxyGameDefinition(
            profile.Id,
            profile.DisplayName,
            profile.ProcessPatterns,
            profile.PatternsAreRegex,
            true,
            string.IsNullOrWhiteSpace(profile.Source) ? "custom" : profile.Source);
    }

    private static bool IsRunning(WindowsProxyGameDefinition definition, IReadOnlyList<string> running)
    {
        foreach (var process in definition.ProcessNames)
        {
            if (!definition.PatternsAreRegex)
            {
                var normalized = NormalizeProcessName(process);
                if (running.Any(name => string.Equals(NormalizeProcessName(name), normalized, StringComparison.OrdinalIgnoreCase)))
                    return true;
                continue;
            }

            try
            {
                if (running.Any(name => Regex.IsMatch(name, process, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)))
                    return true;
            }
            catch (ArgumentException)
            {
                // Invalid imported regexes are ignored for the running badge;
                // the process adapter will still report a clear validation code.
            }
        }
        return false;
    }

    private static string NormalizeProcessName(string? name)
        => Path.GetFileName((name ?? string.Empty).Trim());
}
