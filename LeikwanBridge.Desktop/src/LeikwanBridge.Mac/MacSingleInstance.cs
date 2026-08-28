namespace LeikwanBridge.Mac;

internal sealed class MacSingleInstance : IDisposable
{
    private readonly FileStream _lock;

    private MacSingleInstance(FileStream lockFile)
    {
        _lock = lockFile;
    }

    public static MacSingleInstance? TryAcquire(string root)
    {
        Directory.CreateDirectory(root);
        var path = root.TrimEnd('/', '\\') + "/client.lock";
        try
        {
            var stream = new FileStream(path, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
            return new MacSingleInstance(stream);
        }
        catch (IOException)
        {
            return null;
        }
    }

    public void Dispose() => _lock.Dispose();
}
