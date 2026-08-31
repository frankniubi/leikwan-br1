using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>Background, resumable upload of redacted desktop logs.</summary>
public sealed partial class DebugLogUploader
{
    public const int ChunkBytes = 256 * 1024;
    public const long ByteLimit = 100 * 1024 * 1024;
    private const int MaxFiles = 32;
    private readonly string _logDirectory;
    private readonly string _statePath;
    private readonly object _gate = new();
    private Task? _running;

    public DebugLogUploader(string logDirectory, string stateDirectory)
    {
        _logDirectory = logDirectory ?? string.Empty;
        _statePath = Path.Combine(stateDirectory ?? string.Empty, "debug-upload-state.json");
    }

    public void Queue(ClientMeta client, MasterApiClient api, CancellationToken ct = default)
    {
        var collection = client.DebugCollection;
        if (collection is null
            || !Guid.TryParse(collection.Uuid, out _)
            || !string.Equals(collection.Status, "active", StringComparison.OrdinalIgnoreCase))
            return;
        lock (_gate)
        {
            if (_running is { IsCompleted: false })
                return;
            _running = Task.Run(() => UploadAsync(client, api, ct), CancellationToken.None);
        }
    }

    public async Task<DebugUploadResult> UploadAsync(ClientMeta client, MasterApiClient api, CancellationToken ct = default)
    {
        var collection = client.DebugCollection;
        if (collection is null || !Guid.TryParse(collection.Uuid, out var parsedUuid)
            || !string.Equals(collection.Status, "active", StringComparison.OrdinalIgnoreCase))
            return new("inactive", 0, 0);
        if (DateTimeOffset.TryParse(collection.ExpiresAt, out var expires) && expires <= DateTimeOffset.UtcNow)
            return new("expired", 0, 0);

        var uuid = parsedUuid.ToString();
        var state = LoadState(uuid);
        if (state.Completed)
            return new("complete", 0, 0);
        var files = EnumerateLogFiles().Take(MaxFiles).ToArray();
        var bytes = 0L;
        var chunks = 0;
        foreach (var file in files)
        {
            ct.ThrowIfCancellationRequested();
            if (bytes >= ByteLimit)
                break;
            byte[] raw;
            try
            {
                using var stream = new FileStream(file, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
                var length = (int)Math.Min(Math.Max(0, ByteLimit - bytes), Math.Min(stream.Length, ByteLimit));
                raw = new byte[length];
                var read = 0;
                while (read < raw.Length)
                {
                    var count = await stream.ReadAsync(raw.AsMemory(read, raw.Length - read), ct);
                    if (count == 0) break;
                    read += count;
                }
                if (read != raw.Length)
                    Array.Resize(ref raw, read);
            }
            catch (IOException) { continue; }
            if (raw.Length == 0) continue;
            var sanitized = Encoding.UTF8.GetBytes(Redact(Encoding.UTF8.GetString(raw)));
            if (sanitized.Length > ByteLimit - bytes)
                Array.Resize(ref sanitized, (int)(ByteLimit - bytes));
            var fileHash = Convert.ToHexString(SHA256.HashData(sanitized)).ToLowerInvariant();
            if (state.Files.TryGetValue(file, out var oldHash) && string.Equals(oldHash, fileHash, StringComparison.Ordinal))
                continue;

            for (var offset = 0; offset < sanitized.Length; offset += ChunkBytes)
            {
                var length = Math.Min(ChunkBytes, sanitized.Length - offset);
                var chunk = sanitized.AsSpan(offset, length).ToArray();
                var sha = Convert.ToHexString(SHA256.HashData(chunk)).ToLowerInvariant();
                var sequence = state.NextSequence;
                var uploaded = false;
                Exception? last = null;
                for (var attempt = 0; attempt < 3; attempt++)
                {
                    try
                    {
                        await api.SubmitDebugLogChunkAsync(uuid, sequence, sha, Convert.ToBase64String(chunk), Path.GetFileName(file), ct);
                        uploaded = true;
                        break;
                    }
                    catch (MasterApiException ex) when (ex.StatusCode is >= 400 and < 500)
                    {
                        last = ex;
                        break;
                    }
                    catch (Exception ex) when (attempt < 2)
                    {
                        last = ex;
                        await Task.Delay(TimeSpan.FromMilliseconds(500 * (attempt + 1)), ct);
                    }
                    catch (Exception ex) { last = ex; }
                }
                if (!uploaded)
                {
                    Log.Warning(last, "debug log upload paused at sequence {Sequence}", sequence);
                    SaveState(state);
                    return new("retry_later", chunks, bytes);
                }
                state.NextSequence++;
                chunks++;
                bytes += chunk.Length;
                SaveState(state);
            }
            state.Files[file] = fileHash;
            SaveState(state);
        }
        state.Completed = true;
        SaveState(state);
        return new("complete", chunks, bytes);
    }

    public static string Redact(string value)
    {
        var text = (value ?? string.Empty).Replace("\0", string.Empty);
        text = BearerPattern().Replace(text, "$1[REDACTED]");
        text = SecretPattern().Replace(text, "$1[REDACTED]");
        text = QuerySecretPattern().Replace(text, "$1[REDACTED]");
        return string.Join('\n', text.Split('\n').Select(line => new string(line.Where(ch => ch == '\t' || ch == '\r' || ch >= ' ').ToArray())));
    }

    private IEnumerable<string> EnumerateLogFiles()
    {
        if (!Directory.Exists(_logDirectory))
            yield break;
        IEnumerable<string> files;
        try { files = Directory.EnumerateFiles(_logDirectory, "*", SearchOption.TopDirectoryOnly); }
        catch (IOException) { yield break; }
        foreach (var path in files.Where(IsLogFile).OrderByDescending(GetLastWriteTimeUtc))
            yield return path;
    }

    private static bool IsLogFile(string path)
        => new[] { ".log", ".txt", ".out", ".err" }.Contains(Path.GetExtension(path), StringComparer.OrdinalIgnoreCase);

    private static DateTime GetLastWriteTimeUtc(string path)
    {
        try { return File.GetLastWriteTimeUtc(path); }
        catch { return DateTime.MinValue; }
    }

    private UploadState LoadState(string uuid)
    {
        try
        {
            var state = JsonSerializer.Deserialize<UploadState>(File.ReadAllText(_statePath));
            if (state is not null && string.Equals(state.CollectionUuid, uuid, StringComparison.OrdinalIgnoreCase))
                return state;
        }
        catch { }
        return new UploadState { CollectionUuid = uuid };
    }

    private void SaveState(UploadState state)
    {
        try
        {
            var directory = Path.GetDirectoryName(_statePath);
            if (!string.IsNullOrWhiteSpace(directory)) Directory.CreateDirectory(directory);
            var temp = _statePath + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(state));
            File.Move(temp, _statePath, true);
        }
        catch (IOException) { }
    }

    private sealed class UploadState
    {
        public string CollectionUuid { get; set; } = "";
        public int NextSequence { get; set; }
        public Dictionary<string, string> Files { get; set; } = new(StringComparer.OrdinalIgnoreCase);
        public bool Completed { get; set; }
    }

    [GeneratedRegex("(?i)(authorization\\s*:\\s*bearer\\s+)[^\\s,;]+", RegexOptions.CultureInvariant)]
    private static partial Regex BearerPattern();

    [GeneratedRegex("(?i)(\\b(?:password|passwd|secret|token|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\\b\\s*[:=]\\s*)(?:\\\"[^\\\"]*\\\"|'[^']*'|[^\\s,;]+)", RegexOptions.CultureInvariant)]
    private static partial Regex SecretPattern();

    [GeneratedRegex("(?i)([?&](?:token|password|secret|credential|key)=)[^&\\s]+", RegexOptions.CultureInvariant)]
    private static partial Regex QuerySecretPattern();
}

public readonly record struct DebugUploadResult(string Status, int Chunks, long Bytes);
