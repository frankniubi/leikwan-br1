namespace LeikwanBridge.Core.Tests.Runtime;

internal sealed class TempStateDirectory : IDisposable
{
    public TempStateDirectory()
    {
        Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "leikwan-desktop-tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path);
    }

    public string Path { get; }

    public void Dispose()
    {
        if (Directory.Exists(Path))
        {
            Directory.Delete(Path, recursive: true);
        }
    }
}
