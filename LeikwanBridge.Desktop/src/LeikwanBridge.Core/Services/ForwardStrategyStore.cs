using System.Security.Cryptography;
using System.Text;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class ForwardStrategyStore
{
    private readonly string _strategyDirectory;

    public ForwardStrategyStore(string stateDirectory, string panelId, int clientId)
    {
        var panelKey = Convert.ToHexString(
            SHA256.HashData(Encoding.UTF8.GetBytes(panelId.Trim().ToLowerInvariant()))
        ).ToLowerInvariant()[..16];
        _strategyDirectory = Path.Combine(
            stateDirectory,
            "forward-strategies",
            panelKey,
            Math.Max(0, clientId).ToString());
    }

    public string RuntimePath(int forwardId)
    {
        if (forwardId <= 0)
            throw new ArgumentOutOfRangeException(nameof(forwardId));
        return Path.Combine(_strategyDirectory, $"{forwardId}.strategy");
    }

    public ForwardStrategy Get(int forwardId)
    {
        try
        {
            return ForwardStrategyValues.ParseOrDefault(File.ReadAllText(RuntimePath(forwardId)));
        }
        catch (IOException)
        {
            return ForwardStrategy.LatencyBest;
        }
        catch (UnauthorizedAccessException)
        {
            return ForwardStrategy.LatencyBest;
        }
    }

    public void Set(int forwardId, ForwardStrategy strategy)
    {
        var destination = RuntimePath(forwardId);
        Directory.CreateDirectory(_strategyDirectory);
        var temporary = Path.Combine(_strategyDirectory, $".{forwardId}.{Guid.NewGuid():N}.tmp");
        try
        {
            using (var stream = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None))
            using (var writer = new StreamWriter(stream, new UTF8Encoding(false)))
            {
                writer.Write(strategy.ToWireValue());
                writer.Write('\n');
                writer.Flush();
                stream.Flush(flushToDisk: true);
            }
            File.Move(temporary, destination, overwrite: true);
        }
        finally
        {
            if (File.Exists(temporary))
                File.Delete(temporary);
        }
    }

    public string EnsureRuntimeFile(int forwardId)
    {
        var path = RuntimePath(forwardId);
        if (!File.Exists(path))
            Set(forwardId, ForwardStrategy.LatencyBest);
        return path;
    }
}
