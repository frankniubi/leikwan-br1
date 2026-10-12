using System.Text.RegularExpressions;
using System.Xml.Linq;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Views;

/// <summary>Reads the English base and the zh-CN overlay string dictionaries.</summary>
internal static class LocalizedStrings
{
    private static readonly XName Key = XName.Get("Key", "http://schemas.microsoft.com/winfx/2006/xaml");

    public static string DesktopRoot()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac")))
            root = root.Parent;
        Assert.NotNull(root);
        return root!.FullName;
    }

    public static string MacRoot(string desktopRoot) => Path.Combine(desktopRoot, "src", "LeikwanBridge.Mac");

    public static IReadOnlyDictionary<string, string> English(string desktopRoot) => Load(desktopRoot, "Strings.axaml");

    public static IReadOnlyDictionary<string, string> ZhCn(string desktopRoot) => Load(desktopRoot, "Strings.zh-CN.axaml");

    private static Dictionary<string, string> Load(string desktopRoot, string file)
        => XDocument.Load(Path.Combine(MacRoot(desktopRoot), "Resources", file)).Root!.Elements()
            .ToDictionary(element => (string)element.Attribute(Key)!, element => element.Value);
}

public sealed class LocalizationTests
{
    /// <summary>The screens of the redesigned window: every user-visible string must come from the catalog.</summary>
    private static readonly string[] Views =
        ["MainWindow.axaml", "DashboardView.axaml", "NodesView.axaml", "SettingsView.axaml"];

    private static readonly string[] TextAttributes =
        ["Text", "Watermark", "PlaceholderText", "ToolTip.Tip", "Content", "Header", "Title",
         "AutomationProperties.Name", "OnContent", "OffContent"];

    /// <summary>Technical sample values (addresses, ports, URI schemes) that are not language.</summary>
    private static readonly HashSet<string> TechnicalLiterals =
        ["27", "443", "node.example.com", "10.8.0.2/32", "0.0.0.0/0, ::/0", "1420", "25", "ss://, wg:// URI", "MTU"];

    private static readonly Regex Cjk = new(@"[　-〿㐀-鿿豈-﫿＀-￯]", RegexOptions.Compiled);

    [Fact]
    public void EnglishAndChineseCatalogsHaveTheSameKeys()
    {
        var root = LocalizedStrings.DesktopRoot();
        var english = LocalizedStrings.English(root);
        var chinese = LocalizedStrings.ZhCn(root);

        Assert.Empty(english.Keys.Except(chinese.Keys));
        Assert.Empty(chinese.Keys.Except(english.Keys));
        Assert.All(chinese, pair => Assert.False(string.IsNullOrWhiteSpace(pair.Value), pair.Key));
    }

    [Fact]
    public void EnglishCatalogHasNoChineseExceptTheLanguageSelfName()
    {
        var english = LocalizedStrings.English(LocalizedStrings.DesktopRoot());

        Assert.Empty(english.Where(pair => pair.Key != "LanguageNamezh-CN" && Cjk.IsMatch(pair.Value)).Select(pair => pair.Key));
    }

    [Theory]
    [MemberData(nameof(ViewFiles))]
    public void ViewsHaveNoHardCodedCjk(string view)
    {
        var text = StripXmlComments(File.ReadAllText(ViewPath(view)));

        Assert.False(Cjk.IsMatch(text), $"{view} contains hard-coded CJK text: {Cjk.Match(text).Value}");
    }

    [Theory]
    [MemberData(nameof(ViewFiles))]
    public void ViewsTakeEveryVisibleStringFromTheCatalog(string view)
    {
        var root = LocalizedStrings.DesktopRoot();
        var english = LocalizedStrings.English(root);
        var document = XDocument.Load(ViewPath(view));
        var literals = new List<string>();
        foreach (var attribute in document.Descendants().SelectMany(element => element.Attributes()))
        {
            if (!TextAttributes.Contains(attribute.Name.LocalName) && !TextAttributes.Contains(QualifiedName(attribute)))
                continue;
            var value = attribute.Value;
            if (value.Length == 0 || value.StartsWith('{') || TechnicalLiterals.Contains(value))
                continue;
            literals.Add($"{QualifiedName(attribute)}=\"{value}\"");
        }
        Assert.Empty(literals);

        var keys = Regex.Matches(File.ReadAllText(ViewPath(view)), @"\{DynamicResource (\w[\w\-.]*)\}")
            .Select(match => match.Groups[1].Value)
            .Where(key => !key.EndsWith("Brush", StringComparison.Ordinal) && !key.EndsWith("CornerRadius", StringComparison.Ordinal))
            .Distinct();
        Assert.Empty(keys.Where(key => !english.ContainsKey(key)));
    }

    [Fact]
    public void CodeLookupsExistInBothCatalogsWithTheSameEnglishText()
    {
        var root = LocalizedStrings.DesktopRoot();
        var english = LocalizedStrings.English(root);
        var chinese = LocalizedStrings.ZhCn(root);
        var call = new Regex(@"L\.[TF]\(\s*""(?<key>[^""]+)"",\s*""(?<text>(?:[^""\\]|\\.)*)""", RegexOptions.Compiled);
        var problems = new List<string>();
        var count = 0;
        foreach (var file in CodeFiles(root))
        {
            foreach (Match match in call.Matches(File.ReadAllText(file)))
            {
                count++;
                var key = match.Groups["key"].Value;
                var text = Regex.Unescape(match.Groups["text"].Value);
                if (!english.TryGetValue(key, out var value))
                    problems.Add($"{Path.GetFileName(file)}: {key} missing from Strings.axaml");
                else if (value != text)
                    problems.Add($"{Path.GetFileName(file)}: {key} fallback \"{text}\" != catalog \"{value}\"");
                if (!chinese.ContainsKey(key))
                    problems.Add($"{Path.GetFileName(file)}: {key} missing from Strings.zh-CN.axaml");
            }
        }
        Assert.True(count > 100, $"only {count} localized lookups found");
        Assert.Empty(problems);
    }

    [Fact]
    public void CodeHasNoHardCodedCjkStrings()
    {
        var root = LocalizedStrings.DesktopRoot();
        var offenders = new List<string>();
        var literal = new Regex(@"""(?:[^""\\\n]|\\.)*""", RegexOptions.Compiled);
        foreach (var file in CodeFiles(root))
        {
            var code = StripCodeComments(File.ReadAllText(file));
            offenders.AddRange(literal.Matches(code).Select(match => match.Value)
                .Where(value => Cjk.IsMatch(value))
                .Select(value => $"{Path.GetFileName(file)}: {value}"));
        }
        Assert.Empty(offenders);
    }

    [Fact]
    public void ConvertedOptionValuesHaveCatalogEntries()
    {
        var root = LocalizedStrings.DesktopRoot();
        var english = LocalizedStrings.English(root);
        var chinese = LocalizedStrings.ZhCn(root);
        var keys = new List<string>();
        keys.AddRange(Enum.GetNames<MacConnectionMode>().Select(name => "Mode" + name));
        keys.AddRange(DesktopLogLevel.Options.Select(level => "LogLevel" + level));
        keys.AddRange(PanelEndpoints.Options.Select(option => "Endpoint" + option.Key));
        keys.AddRange(MacConnectionProtocol.Editable.Select(protocol => "ProtocolOption" + protocol));
        keys.AddRange(new[] { "" }.Concat(UserNodeProtocol.ShadowsocksPlugins).Select(plugin => "PluginOption" + plugin));
        keys.AddRange(new[] { "en-US", "zh-CN" }.Select(language => "LanguageName" + language));

        Assert.Empty(keys.Where(key => !english.ContainsKey(key) || !chinese.ContainsKey(key)));
    }

    [Fact]
    public void OwnNodesAreNeverLabelledByProtocol()
    {
        var root = LocalizedStrings.DesktopRoot();
        var protocolBindings = new[] { "{Binding Protocol}", "{Binding DisplayProtocol}", "{Binding ProtocolLabel}", "{Binding ProtocolInitial}" };
        foreach (var view in Views)
        {
            var text = File.ReadAllText(ViewPath(view));
            Assert.DoesNotContain(protocolBindings, binding => text.Contains(binding, StringComparison.Ordinal));
        }
        var english = LocalizedStrings.English(root);
        var chinese = LocalizedStrings.ZhCn(root);
        Assert.Equal("Your own node", english["OwnNode"]);
        Assert.Equal("自带节点", chinese["OwnNode"]);
        // Protocol names only where a node is added or imported.
        var protocols = new Regex("Shadowsocks|SOCKS5|VLESS|WireGuard", RegexOptions.IgnoreCase);
        string[] listKeys = ["NodeBadgeCloud", "NodeBadgeThisMac", "NodeBadgeLocalOnly", "NodeBadgeCloudTip", "NodeBadgeThisMacTip",
            "NodeBadgeLocalOnlyTip", "SyncAllTip", "NodeRoute", "PairedServiceLabel", "NoSavedNodeSelected"];
        Assert.Empty(listKeys.Where(key => protocols.IsMatch(english[key]) || protocols.IsMatch(chinese[key])));
        Assert.Matches(protocols, english["ShareLinkWatermark"]);
    }

    public static IEnumerable<object[]> ViewFiles() => Views.Select(view => new object[] { view });

    private static string ViewPath(string view)
        => Path.Combine(LocalizedStrings.MacRoot(LocalizedStrings.DesktopRoot()), "Views", view);

    private static IEnumerable<string> CodeFiles(string root)
    {
        var mac = LocalizedStrings.MacRoot(root);
        return Directory.EnumerateFiles(Path.Combine(mac, "ViewModels"), "*.cs")
            .Concat(Directory.EnumerateFiles(Path.Combine(mac, "Views"), "*.cs"))
            .Concat(Directory.EnumerateFiles(Path.Combine(mac, "Localization"), "*.cs"))
            .Concat([Path.Combine(mac, "App.axaml.cs"), Path.Combine(mac, "Services", "MacConnectionModels.cs")]);
    }

    private static string QualifiedName(XAttribute attribute)
        => attribute.Name.Namespace == XNamespace.None ? attribute.Name.LocalName : attribute.Name.LocalName;

    private static string StripXmlComments(string text) => Regex.Replace(text, "<!--.*?-->", string.Empty, RegexOptions.Singleline);

    private static string StripCodeComments(string text)
        => Regex.Replace(Regex.Replace(text, @"/\*.*?\*/", string.Empty, RegexOptions.Singleline), @"(?m)^\s*//.*$|\s//[^""\n]*$", string.Empty);
}
