using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyProfileStoreTests
{
    [Fact]
    public async Task StoreNormalizesIdsAndBoundsProfiles()
    {
        using var temp = new TempStateDirectory();
        var store = new WindowsProxyProfileStore(Path.Combine(temp.Path, "proxy-profiles.json"));
        await store.SaveAsync([
            new WindowsProxyProfile { Id = 1, Name = new string('n', 300), Server = "edge.example.test" },
            new WindowsProxyProfile { Id = 1, Name = "duplicate", Server = "edge2.example.test" },
        ]);

        var loaded = await store.LoadAsync();

        Assert.Equal(2, loaded.Count);
        Assert.NotEqual(loaded[0].Id, loaded[1].Id);
        Assert.Equal(120, loaded[0].Name.Length);
    }

    [Fact]
    public async Task WindowsStoreProtectsSerializedCredentialsAtRest()
    {
        if (!OperatingSystem.IsWindows())
            return;

        using var temp = new TempStateDirectory();
        var path = Path.Combine(temp.Path, "proxy-profiles.json");
        var store = new WindowsProxyProfileStore(path);
        await store.SaveAsync([
            new WindowsProxyProfile
            {
                Name = "protected",
                Server = "edge.example.test",
                Method = "aes-256-gcm",
                Password = "credential-that-must-not-be-plain",
            },
        ]);

        var stored = await File.ReadAllTextAsync(path);
        Assert.StartsWith("LWPROXY-DPAPI-1:", stored, StringComparison.Ordinal);
        Assert.DoesNotContain("credential-that-must-not-be-plain", stored, StringComparison.Ordinal);
        Assert.Equal("credential-that-must-not-be-plain", (await store.LoadAsync())[0].Password);
    }
}
