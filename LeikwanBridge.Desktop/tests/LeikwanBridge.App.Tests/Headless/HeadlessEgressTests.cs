using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Headless;
using Xunit;

namespace LeikwanBridge.App.Tests.Headless;

/// <summary>
/// The windowless clients have no settings page, so the adapter pin travels
/// as --egress-interface (persisted to PreferredEgressInterface) and every
/// entry point must start panel direct egress before its first panel call.
/// </summary>
public sealed class HeadlessEgressTests
{
    [Fact]
    public void EgressInterfaceFlagIsParsedAndDocumented()
    {
        Assert.True(HeadlessClientOptions.TryParse(
            ["--headless", "--client-id", "7", "--agent-token", "tok", "--egress-interface", " Wi-Fi "],
            out var options,
            out var error), error);

        Assert.Equal("Wi-Fi", options.PreferredEgressInterface);
        Assert.Contains("--egress-interface <name>", HeadlessClientOptions.Usage, StringComparison.Ordinal);
        Assert.False(HeadlessClientOptions.TryParse(
            ["--headless", "--client-id", "7", "--agent-token", "tok", "--egress-interface"],
            out _,
            out _));
    }

    [Fact]
    public async Task PinnedAdapterThatIsNotEligibleFailsTheStartInsteadOfFallingBack()
    {
        Assert.True(HeadlessClientOptions.TryParse(
            ["--headless", "--client-id", "7", "--agent-token", "tok", "--egress-interface", "adapter-that-does-not-exist"],
            out var options,
            out var error), error);
        var connectCalls = 0;

        var exception = await Assert.ThrowsAsync<InvalidOperationException>(() => HeadlessRunner.RunAsync(
            options,
            CancellationToken.None,
            (_, _, _) =>
            {
                connectCalls++;
                throw new InvalidOperationException("must not connect");
            },
            TextWriter.Null,
            (_, _) => Task.CompletedTask,
            _ => new AgentConfig { MasterUrl = "https://panel.example", ClientId = 7, ActiveClientId = 7, AgentToken = "tok" }));

        Assert.Equal("egress_interface_unavailable", exception.Message);
        Assert.Equal(0, connectCalls);
    }

    [Theory]
    [InlineData("LeikwanBridge.Headless", "HeadlessRunner.cs")]
    [InlineData("LeikwanBridge.Headless", "Program.cs")]
    [InlineData("LeikwanBridge.Mac", "Services", "MacAppBootstrap.cs")]
    public void EveryWindowlessEntryPointStartsDirectEgressAndAppliesThePin(params string[] relativePath)
    {
        var source = File.ReadAllText(Path.Combine(
            [FindRepositoryRoot(), "LeikwanBridge.Desktop", "src", .. relativePath]));

        Assert.Contains("PanelDirectEgress.Initialize()", source, StringComparison.Ordinal);
        Assert.Contains("PanelDirectEgress.SetPreferredInterface(", source, StringComparison.Ordinal);
    }

    private static string FindRepositoryRoot()
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        // A git worktree has a .git file instead of a .git directory.
        while (directory is not null && !File.Exists(Path.Combine(directory.FullName, ".git", "HEAD"))
            && !File.Exists(Path.Combine(directory.FullName, ".git")))
            directory = directory.Parent;
        return directory?.FullName ?? throw new DirectoryNotFoundException("repository root not found");
    }
}
