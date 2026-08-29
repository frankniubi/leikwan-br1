using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyGameProfileStoreTests
{
    [Fact]
    public async Task SaveAndLoadRoundTripsCustomProfiles()
    {
        var root = Path.Combine(Path.GetTempPath(), "proxy-games-" + Guid.NewGuid().ToString("N"));
        var path = Path.Combine(root, "proxy-games.json");
        try
        {
            var store = new WindowsProxyGameProfileStore(path);
            await store.SaveAsync([
                new WindowsProxyGameProfile
                {
                    Id = "custom:demo",
                    DisplayName = "Demo",
                    ProcessPatterns = ["Demo.exe"],
                    Source = "custom",
                },
            ]);

            var loaded = await store.LoadAsync();

            var profile = Assert.Single(loaded);
            Assert.Equal("custom:demo", profile.Id);
            Assert.Equal("Demo.exe", Assert.Single(profile.ProcessPatterns));
        }
        finally
        {
            try { Directory.Delete(root, recursive: true); } catch (IOException) { }
        }
    }
}
