using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ForwardStrategyStoreTests : IDisposable
{
    private readonly string _stateDir = Path.Combine(Path.GetTempPath(), $"leikwan-strategy-{Guid.NewGuid():N}");

    [Fact]
    public void MissingChoiceDefaultsToLatencyBest()
    {
        var store = new ForwardStrategyStore(_stateDir, "panel-a", 9);
        Assert.Equal(ForwardStrategy.LatencyBest, store.Get(77));
    }

    [Fact]
    public void SaveIsIsolatedByPanelClientAndForward()
    {
        new ForwardStrategyStore(_stateDir, "panel-a", 9).Set(77, ForwardStrategy.Random);

        Assert.Equal(ForwardStrategy.Random, new ForwardStrategyStore(_stateDir, "panel-a", 9).Get(77));
        Assert.Equal(ForwardStrategy.LatencyBest, new ForwardStrategyStore(_stateDir, "panel-b", 9).Get(77));
        Assert.Equal(ForwardStrategy.LatencyBest, new ForwardStrategyStore(_stateDir, "panel-a", 10).Get(77));
        Assert.Equal(ForwardStrategy.LatencyBest, new ForwardStrategyStore(_stateDir, "panel-a", 9).Get(78));
    }

    [Fact]
    public void EnsureRuntimeFileDoesNotOverwriteSavedChoice()
    {
        var store = new ForwardStrategyStore(_stateDir, "panel-a", 9);
        store.Set(77, ForwardStrategy.Random);

        var path = store.EnsureRuntimeFile(77);

        Assert.Equal("random\n", File.ReadAllText(path));
    }

    public void Dispose()
    {
        if (Directory.Exists(_stateDir))
            Directory.Delete(_stateDir, recursive: true);
    }
}
