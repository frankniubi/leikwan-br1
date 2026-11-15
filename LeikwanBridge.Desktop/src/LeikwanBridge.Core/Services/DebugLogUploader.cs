using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>Uploads an immutable, redacted snapshot. Only acknowledged bytes advance its durable cursor.</summary>
public sealed class DebugLogUploader
{
    public const int ChunkBytes = 256 * 1024;
    public const long ByteLimit = 100 * 1024 * 1024;
    private const int MaxFiles = 32;
    private readonly string _logDirectory;
    private readonly string _statePath;
    private readonly string _lockPath;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private readonly object _gate = new();
    private Task? _running;

    public DebugLogUploader(string logDirectory, string stateDirectory,
        Func<TimeSpan, CancellationToken, Task>? delay = null)
    {
        _logDirectory = logDirectory ?? string.Empty;
        _statePath = Path.Combine(stateDirectory ?? string.Empty, "debug-upload-state.json");
        _lockPath = Path.Combine(stateDirectory ?? string.Empty, "debug-upload.lock");
        _delay = delay ?? ((span, token) => Task.Delay(span, token));
    }

    public void Queue(ClientMeta client, MasterApiClient api, CancellationToken ct = default)
    {
        if (client.DebugCollection is not { SupportsUploadId: true } collection || !IsActive(collection))
            return;
        lock (_gate)
        {
            if (_running is { IsCompleted: false })
                return;
            _running = Task.Run(async () =>
            {
                try { await UploadAsync(client, api, ct).ConfigureAwait(false); }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { }
                catch (Exception ex) { Log.Warning(ex, "debug log upload failed"); }
            }, CancellationToken.None);
        }
    }

    public async Task<DebugUploadResult> UploadAsync(ClientMeta client, MasterApiClient api, CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        var collection = client.DebugCollection;
        if (collection is null || !IsActive(collection))
            return new("inactive", 0, 0);
        if (!collection.SupportsUploadId)
            return new("unsupported_upload_protocol", 0, 0);
        using var uploadLock = UpdateApplyLock.TryAcquire(_lockPath);
        if (uploadLock is null)
            return new("busy", 0, 0);
        var state = LoadState();
        var uuid = Guid.Parse(collection.Uuid).ToString();
        if (state is null || !string.Equals(state.CollectionUuid, uuid, StringComparison.OrdinalIgnoreCase)
            || (state.Version != 0 && state.ScopeBinding != api.DebugUploadScopeBinding))
            state = await CreateSnapshotAsync(collection, null, "", api.DebugUploadScopeBinding, ct).ConfigureAwait(false);
        else if (state.Version == 0)
        {
            // A legacy cursor cannot identify which bytes the server accepted. Never reuse it.
            if (state.Completed)
                return new("complete", 0, 0);
            state = await CreateSnapshotAsync(collection, null, "", api.DebugUploadScopeBinding, ct).ConfigureAwait(false);
        }
        return await SendSnapshotAsync(state, api, ct).ConfigureAwait(false);
    }

    public async Task<DebugManualUploadResult> UploadNowAsync(
        MasterApiClient api, IReadOnlyList<string>? extraLines = null, CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        // Take the same cross-process lock BEFORE /start, which can replace the active collection.
        using var uploadLock = UpdateApplyLock.TryAcquire(_lockPath);
        if (uploadLock is null)
            return new("busy", "", 0, 0);
        var state = LoadState();
        if (state is not { Version: 1, Manual: true } || IsTerminal(state.Status)
            || state.ScopeBinding != api.DebugUploadScopeBinding)
        {
            var started = await api.StartDebugCollectionAsync(ct).ConfigureAwait(false);
            if (!started.Collection.SupportsUploadId)
                return new("unsupported_upload_protocol", started.ReferenceCode, 0, 0);
            if (!IsActive(started.Collection))
                return new("inactive", started.ReferenceCode, 0, 0);
            state = await CreateSnapshotAsync(started.Collection, extraLines, started.ReferenceCode,
                api.DebugUploadScopeBinding, ct).ConfigureAwait(false);
        }
        var result = await SendSnapshotAsync(state, api, ct).ConfigureAwait(false);
        return new(result.Status, state.ReferenceCode, result.Chunks, result.Bytes);
    }

    private async Task<UploadState> CreateSnapshotAsync(DebugCollectionMeta collection,
        IReadOnlyList<string>? extraLines, string referenceCode, string scopeBinding, CancellationToken ct)
    {
        var previous = LoadState();
        var state = new UploadState
        {
            Version = 1,
            ScopeBinding = scopeBinding,
            CollectionUuid = Guid.Parse(collection.Uuid).ToString(),
            UploadId = Guid.NewGuid().ToString(),
            ReferenceCode = referenceCode,
            Manual = referenceCode.Length > 0,
            ExpiresAt = collection.ExpiresAt,
            // A zero/missing limit on older metadata falls back to the hard client ceiling.
            Budget = Math.Max(0, Math.Min(ByteLimit,
                (collection.ByteLimit > 0 ? collection.ByteLimit : ByteLimit) - Math.Max(0, collection.BytesReceived))),
        };
        var spoolPath = SpoolPath(state);
        var temporary = spoolPath + ".tmp";
        Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(_statePath))!);
        try
        {
            using (var spool = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None))
            {
                void Append(string text)
                {
                    var remaining = state.Budget - spool.Length;
                    if (remaining <= 0) return;
                    // Bound both the raw input and final UTF-8 output, including redaction expansion.
                    var bounded = text.Length > remaining ? text[..(int)remaining] : text;
                    var bytes = Encoding.UTF8.GetBytes(Redact(bounded));
                    spool.Write(bytes.AsSpan(0, (int)Math.Min(bytes.Length, remaining)));
                }
                if (state.Manual)
                {
                    Append($"=== client-initiated upload utc={DateTimeOffset.UtcNow:O} os={Environment.OSVersion} ===\n");
                    if (extraLines is { Count: > 0 })
                    {
                        Append("=== in-memory activity ===\n");
                        foreach (var line in extraLines)
                        {
                            ct.ThrowIfCancellationRequested();
                            if (spool.Length >= state.Budget) break;
                            Append(line);
                            Append("\n");
                        }
                    }
                }
                foreach (var file in EnumerateLogFiles().Take(MaxFiles))
                {
                    ct.ThrowIfCancellationRequested();
                    if (spool.Length >= state.Budget) break;
                    byte[] raw;
                    try
                    {
                        using var input = new FileStream(file, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
                        raw = new byte[(int)Math.Min(input.Length, state.Budget - spool.Length)];
                        var read = 0;
                        while (read < raw.Length)
                        {
                            var count = await input.ReadAsync(raw.AsMemory(read), ct).ConfigureAwait(false);
                            if (count == 0) break;
                            read += count;
                        }
                        if (read != raw.Length) Array.Resize(ref raw, read);
                    }
                    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { continue; }
                    if (raw.Length == 0) continue;
                    Append($"=== file: {Path.GetFileName(file)} ===\n");
                    Append(Encoding.UTF8.GetString(raw));
                    Append("\n");
                }
                ct.ThrowIfCancellationRequested();
                state.Length = spool.Length;
                spool.Flush(flushToDisk: true);
            }
            File.Move(temporary, spoolPath);
            using (var spool = File.OpenRead(spoolPath))
                state.Sha256 = Convert.ToHexString(SHA256.HashData(spool));
            SaveState(state); // No request is allowed until both spool and manifest are durable.
        }
        catch
        {
            TryDelete(temporary);
            TryDelete(spoolPath);
            throw;
        }
        if (previous is { Version: 1 } && Guid.TryParse(previous.UploadId, out _))
            TryDelete(SpoolPath(previous));
        return state;
    }

    private async Task<DebugUploadResult> SendSnapshotAsync(UploadState state, MasterApiClient api, CancellationToken ct)
    {
        ValidateState(state);
        if (state.ScopeBinding != api.DebugUploadScopeBinding)
            throw new IOException("debug_upload_scope_mismatch");
        if (IsTerminal(state.Status))
            return Result(state);
        if (DateTimeOffset.TryParse(state.ExpiresAt, out var expires) && expires <= DateTimeOffset.UtcNow)
        {
            state.Status = "expired";
            SaveState(state);
            return Result(state);
        }
        using var spool = new FileStream(SpoolPath(state), FileMode.Open, FileAccess.Read, FileShare.Read);
        if (spool.Length != state.Length || Convert.ToHexString(SHA256.HashData(spool)) != state.Sha256)
            throw new IOException("debug_upload_spool_invalid");
        spool.Position = state.AcknowledgedBytes;
        var buffer = new byte[ChunkBytes];
        while (state.AcknowledgedBytes < state.Length)
        {
            ct.ThrowIfCancellationRequested();
            var length = (int)Math.Min(ChunkBytes, state.Length - state.AcknowledgedBytes);
            await spool.ReadExactlyAsync(buffer.AsMemory(0, length), ct).ConfigureAwait(false);
            var chunk = buffer.AsSpan(0, length).ToArray();
            var sha = Convert.ToHexString(SHA256.HashData(chunk)).ToLowerInvariant();
            state.Status = "ready";
            SaveState(state);
            for (var attempt = 0; ; attempt++)
            {
                ct.ThrowIfCancellationRequested();
                try
                {
                    await api.SubmitDebugLogChunkAsync(state.CollectionUuid, state.NextSequence, sha,
                        Convert.ToBase64String(chunk), "desktop.log", ct, state.UploadId).ConfigureAwait(false);
                    break;
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
                catch (MasterApiException ex)
                {
                    var status = ex.StatusCode switch
                    {
                        404 when ex.ErrorCode == "debug_collection_not_found" => "not_found",
                        409 when ex.ErrorCode == "debug_chunk_replay" => "replay_conflict",
                        404 or 409 => "retry_later",
                        410 => "expired",
                        413 => "quota_exceeded",
                        422 => "invalid",
                        401 or 403 => "paused_auth",
                        408 or 429 or >= 500 => "retry_later",
                        _ => "invalid",
                    };
                    if (status != "retry_later" || attempt >= 2)
                    {
                        state.Status = status;
                        SaveState(state);
                        return Result(state);
                    }
                }
                catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or TimeoutException)
                {
                    if (attempt >= 2)
                    {
                        state.Status = "retry_later";
                        SaveState(state);
                        return Result(state);
                    }
                }
                await _delay(TimeSpan.FromMilliseconds(500 * (attempt + 1)), ct).ConfigureAwait(false);
            }
            state.NextSequence++;
            state.AcknowledgedBytes += length;
            state.Status = state.AcknowledgedBytes == state.Length ? "complete" : "ready";
            SaveState(state); // If persistence fails after ACK, retry this exact chunk on restart.
        }
        state.Status = "complete";
        SaveState(state);
        return Result(state);
    }

    private static DebugUploadResult Result(UploadState state)
        => new(state.Status, state.NextSequence, state.AcknowledgedBytes);

    private static bool IsActive(DebugCollectionMeta collection)
        => Guid.TryParse(collection.Uuid, out _) && string.Equals(collection.Status, "active", StringComparison.OrdinalIgnoreCase);

    private static bool IsTerminal(string status)
        => status is "complete" or "expired" or "not_found" or "replay_conflict" or "quota_exceeded" or "invalid";

    private static void ValidateState(UploadState state)
    {
        if (state.Version != 1 || !Guid.TryParse(state.CollectionUuid, out _)
            || !Guid.TryParse(state.UploadId, out var uploadId) || uploadId.ToString()[14] != '4'
            || state.Length < 0 || state.Length > state.Budget || state.Budget > ByteLimit || state.Budget < 0
            || state.AcknowledgedBytes < 0 || state.AcknowledgedBytes > state.Length
            || state.NextSequence != (state.AcknowledgedBytes + ChunkBytes - 1) / ChunkBytes
            || (state.AcknowledgedBytes != state.Length && state.AcknowledgedBytes % ChunkBytes != 0)
            || state.Status is not ("ready" or "retry_later" or "paused_auth" or "complete" or "expired"
                or "not_found" or "replay_conflict" or "quota_exceeded" or "invalid")
            || (state.Status == "complete" && state.AcknowledgedBytes != state.Length))
            throw new IOException("debug_upload_state_invalid");
    }

    public static string Redact(string value) => LogPrivacy.Redact(value);

    private IEnumerable<string> EnumerateLogFiles()
    {
        if (!Directory.Exists(_logDirectory)) return [];
        return Directory.GetFiles(_logDirectory, "*", SearchOption.TopDirectoryOnly)
            .Where(path => new[] { ".log", ".txt", ".out", ".err" }.Contains(Path.GetExtension(path), StringComparer.OrdinalIgnoreCase))
            .OrderByDescending(File.GetLastWriteTimeUtc);
    }

    private string SpoolPath(UploadState state)
        => Path.Combine(Path.GetDirectoryName(_statePath) ?? "", $"debug-upload-{Guid.Parse(state.UploadId):D}.spool");

    private UploadState? LoadState()
    {
        try
        {
            return JsonSerializer.Deserialize<UploadState>(File.ReadAllText(_statePath))
                ?? throw new IOException("debug_upload_state_invalid");
        }
        catch (FileNotFoundException) { return null; }
        catch (DirectoryNotFoundException) { return null; }
        catch (JsonException ex) { throw new IOException("debug_upload_state_invalid", ex); }
    }

    private void SaveState(UploadState state)
    {
        var temporary = _statePath + $".{Guid.NewGuid():N}.tmp";
        try
        {
            using (var stream = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None))
            {
                JsonSerializer.Serialize(stream, state);
                stream.Flush(flushToDisk: true);
            }
            File.Move(temporary, _statePath, overwrite: true);
        }
        finally { TryDelete(temporary); }
    }

    private static void TryDelete(string path)
    {
        try { File.Delete(path); }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
    }

    private sealed class UploadState
    {
        public int Version { get; set; }
        public string ScopeBinding { get; set; } = "";
        public string CollectionUuid { get; set; } = "";
        public string UploadId { get; set; } = "";
        public string ReferenceCode { get; set; } = "";
        public bool Manual { get; set; }
        public string ExpiresAt { get; set; } = "";
        public long Budget { get; set; }
        public long Length { get; set; }
        public string Sha256 { get; set; } = "";
        public int NextSequence { get; set; }
        public long AcknowledgedBytes { get; set; }
        public string Status { get; set; } = "ready";
        public bool Completed { get; set; } // Read-only compatibility with legacy manifests.
    }
}

public readonly record struct DebugUploadResult(string Status, int Chunks, long Bytes);

public readonly record struct DebugManualUploadResult(string Status, string ReferenceCode, int Chunks, long Bytes);
