using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class DebugLogUploaderTests : IDisposable
{
    private readonly string _root = Directory.CreateTempSubdirectory("debug-spool-test-").FullName;
    private string Logs => Path.Combine(_root, "logs");
    private string StateDirectory => Path.Combine(_root, "state");
    private string StatePath => Path.Combine(StateDirectory, "debug-upload-state.json");
    private const string CollectionId = "deadbeef-e89b-42d3-a456-426614174000";

    private static ClientMeta Client(bool capable = true, long limit = DebugLogUploader.ByteLimit, long received = 0) => new()
    {
        DebugCollection = new DebugCollectionMeta
        {
            Uuid = CollectionId, Status = "active", SupportsUploadId = capable,
            ExpiresAt = "2999-01-01T00:00:00Z", ByteLimit = limit, BytesReceived = received,
        },
    };

    private DebugLogUploader Uploader() => new(Logs, StateDirectory, (_, ct) =>
    {
        ct.ThrowIfCancellationRequested();
        return Task.CompletedTask;
    });

    private async Task WriteLog(string text)
    {
        Directory.CreateDirectory(Logs);
        await File.WriteAllTextAsync(Path.Combine(Logs, "client.log"), text);
    }

    private static HttpResponseMessage Response(HttpStatusCode code = HttpStatusCode.OK, string json = "{\"ok\":true}")
        => new(code) { Content = new StringContent(json, Encoding.UTF8, "application/json") };

    private static HttpResponseMessage StartResponse(bool capable = true) => Response(json: JsonSerializer.Serialize(new
    {
        ok = true,
        reference_code = "deadbeef",
        debug_collection = new
        {
            uuid = CollectionId, status = "active", expires_at = "2999-01-01T00:00:00Z",
            byte_limit = DebugLogUploader.ByteLimit, bytes_received = 0, supports_upload_id = capable,
        },
    }));

    private static MasterApiClient Api(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> handler,
        string panel = "https://panel.example.test", string token = "test-token", string device = "")
        => new(panel, token, device, fallbackEndpoints: [],
            httpClientFactory: () => new HttpClient(new Handler(handler)));

    private static async Task<Chunk> ReadChunk(HttpRequestMessage request)
    {
        using var json = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
        var root = json.RootElement;
        var bytes = Convert.FromBase64String(root.GetProperty("chunk_base64").GetString()!);
        Assert.Equal(Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant(), root.GetProperty("sha256").GetString());
        var uploadId = root.GetProperty("upload_id").GetString()!;
        Assert.True(Guid.TryParseExact(uploadId, "D", out _));
        Assert.Equal('4', uploadId[14]);
        return new(uploadId, root.GetProperty("sequence").GetInt32(), bytes);
    }

    [Fact]
    public async Task RestartAfterPartialUploadResumesPersistedCursorAndBudget()
    {
        await WriteLog(new string('x', DebugLogUploader.ChunkBytes * 2));
        var sent = new List<Chunk>();
        using var firstApi = Api(async (request, _) =>
        {
            var chunk = await ReadChunk(request);
            sent.Add(chunk);
            using var manifest = JsonDocument.Parse(await File.ReadAllTextAsync(StatePath));
            Assert.Equal(chunk.Sequence, manifest.RootElement.GetProperty("NextSequence").GetInt32());
            return chunk.Sequence == 0 ? Response() : Response(HttpStatusCode.TooManyRequests);
        });
        var first = await Uploader().UploadAsync(Client(limit: DebugLogUploader.ChunkBytes * 2), firstApi);
        Assert.Equal("retry_later", first.Status);
        Assert.Equal(DebugLogUploader.ChunkBytes, first.Bytes);
        Assert.Equal(1, first.Chunks);
        await WriteLog("MUTATED LOG CONTENT");
        using var secondApi = Api(async (request, _) =>
        {
            var chunk = await ReadChunk(request);
            Assert.Equal(1, chunk.Sequence);
            Assert.Equal(sent[1].UploadId, chunk.UploadId);
            Assert.Equal(sent[1].Bytes, chunk.Bytes);
            return Response();
        });
        var resumed = await Uploader().UploadAsync(Client(limit: DebugLogUploader.ChunkBytes * 2), secondApi);
        Assert.Equal("complete", resumed.Status);
        Assert.Equal(DebugLogUploader.ChunkBytes * 2, resumed.Bytes);
        Assert.Equal(2, resumed.Chunks);
    }

    [Fact]
    public async Task LostAckAndMutatedLogsReplayExactBytesAndUploadIdAfterRestart()
    {
        await WriteLog("password=secret-before-restart\n" + new string('x', DebugLogUploader.ChunkBytes));
        var sent = new List<Chunk>();
        using var firstApi = Api(async (request, _) =>
        {
            sent.Add(await ReadChunk(request));
            throw new HttpRequestException("response lost after server commit");
        });
        Assert.Equal("retry_later", (await Uploader().UploadAsync(Client(), firstApi)).Status);
        await WriteLog("entirely different bytes");
        Assert.All(sent, chunk => Assert.Equal(sent[0].Bytes, chunk.Bytes));
        var replay = new List<Chunk>();
        using var secondApi = Api(async (request, _) =>
        {
            replay.Add(await ReadChunk(request));
            return Response();
        });
        var result = await Uploader().UploadAsync(Client(), secondApi);
        Assert.Equal("complete", result.Status);
        Assert.Equal(sent[0].UploadId, replay[0].UploadId);
        Assert.Equal(sent[0].Sequence, replay[0].Sequence);
        Assert.Equal(sent[0].Bytes, replay[0].Bytes);
        Assert.DoesNotContain("secret-before-restart", Encoding.UTF8.GetString(replay.SelectMany(x => x.Bytes).ToArray()));
    }

    [Theory]
    [InlineData(404, "not_found", "debug_collection_not_found")]
    [InlineData(409, "replay_conflict", "debug_chunk_replay")]
    [InlineData(410, "expired", "")]
    [InlineData(413, "quota_exceeded", "")]
    [InlineData(422, "invalid", "")]
    public async Task TerminalResponsesPersistAndSuppressFurtherAttempts(int code, string status, string errorCode)
    {
        await WriteLog("diagnostic line");
        var calls = 0;
        using var api = Api((_, _) =>
        {
            calls++;
            return Task.FromResult(Response((HttpStatusCode)code, JsonSerializer.Serialize(new { error = errorCode })));
        });
        Assert.Equal(status, (await Uploader().UploadAsync(Client(), api)).Status);
        await WriteLog("later logs");
        Assert.Equal(status, (await Uploader().UploadAsync(Client(), api)).Status);
        Assert.Equal(1, calls);
        using var state = JsonDocument.Parse(await File.ReadAllTextAsync(StatePath));
        Assert.Equal(status, state.RootElement.GetProperty("Status").GetString());
    }

    [Theory]
    [InlineData(404, "<html>upstream temporarily unavailable</html>")]
    [InlineData(404, "{}")]
    [InlineData(404, "{\"error\":\"route_not_found\"}")]
    [InlineData(409, "<html>upstream conflict</html>")]
    [InlineData(409, "{}")]
    [InlineData(409, "{\"error\":\"another_conflict\"}")]
    public async Task UnknownNotFoundAndConflictRemainRecoverableAcrossRestart(int code, string body)
    {
        await WriteLog("original diagnostics before rollout");
        var sent = new List<Chunk>();
        using var api = Api(async (request, _) =>
        {
            sent.Add(await ReadChunk(request));
            return Response((HttpStatusCode)code, body);
        });
        Assert.Equal("retry_later", (await Uploader().UploadAsync(Client(), api)).Status);
        Assert.Equal(3, sent.Count);
        await WriteLog("mutated diagnostics after rollout");
        using var recovered = Api(async (request, _) =>
        {
            var chunk = await ReadChunk(request);
            Assert.Equal(sent[0].UploadId, chunk.UploadId);
            Assert.Equal(sent[0].Sequence, chunk.Sequence);
            Assert.Equal(sent[0].Bytes, chunk.Bytes);
            return Response();
        });
        Assert.Equal("complete", (await Uploader().UploadAsync(Client(), recovered)).Status);
    }

    [Theory]
    [InlineData(408)]
    [InlineData(429)]
    [InlineData(503)]
    public async Task RetryResponsesPreserveTheSameChunk(int code)
    {
        await WriteLog("diagnostic line");
        var sent = new List<Chunk>();
        using var api = Api(async (request, _) =>
        {
            sent.Add(await ReadChunk(request));
            return sent.Count == 1 ? Response((HttpStatusCode)code) : Response();
        });
        Assert.Equal("complete", (await Uploader().UploadAsync(Client(), api)).Status);
        Assert.Equal(2, sent.Count);
        Assert.Equal(sent[0].UploadId, sent[1].UploadId);
        Assert.Equal(sent[0].Sequence, sent[1].Sequence);
        Assert.Equal(sent[0].Bytes, sent[1].Bytes);
    }

    [Theory]
    [InlineData(401)]
    [InlineData(403)]
    public async Task AuthenticationPausesWithoutDiscardingSpool(int code)
    {
        await WriteLog("original log");
        Chunk? original = null;
        using var api = Api(async (request, _) =>
        {
            original = await ReadChunk(request);
            return Response((HttpStatusCode)code);
        });
        Assert.Equal("paused_auth", (await Uploader().UploadAsync(Client(), api)).Status);
        await WriteLog("changed log");
        using var resumed = Api(async (request, _) =>
        {
            var chunk = await ReadChunk(request);
            Assert.Equal(original!.UploadId, chunk.UploadId);
            Assert.Equal(original.Bytes, chunk.Bytes);
            return Response();
        });
        Assert.Equal("complete", (await Uploader().UploadAsync(Client(), resumed)).Status);
    }

    [Theory]
    [InlineData("token", true)]
    [InlineData("device", true)]
    [InlineData("panel", true)]
    [InlineData("unbound", true)]
    [InlineData("token", false)]
    [InlineData("device", false)]
    [InlineData("panel", false)]
    [InlineData("unbound", false)]
    public async Task ChangedOrMissingIdentityBindingNeverReplaysOldSnapshot(string changed, bool manual)
    {
        await WriteLog("old-account-log-canary");
        Chunk? original = null;
        using var firstApi = Api(async (request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/start")) return StartResponse();
            original = await ReadChunk(request);
            return Response(HttpStatusCode.TooManyRequests);
        }, token: "old-auth-token-canary", device: "old-device-canary");
        Assert.Equal("retry_later", manual
            ? (await Uploader().UploadNowAsync(firstApi, ["old-account-memory-canary"])).Status
            : (await Uploader().UploadAsync(Client(), firstApi)).Status);
        Assert.NotNull(original);
        var manifest = await File.ReadAllTextAsync(StatePath);
        Assert.DoesNotContain("old-auth-token-canary", manifest);
        Assert.DoesNotContain("old-device-canary", manifest);
        using (var state = JsonDocument.Parse(manifest))
            Assert.Equal(64, state.RootElement.GetProperty("ScopeBinding").GetString()!.Length);
        if (changed == "unbound")
        {
            var state = System.Text.Json.Nodes.JsonNode.Parse(manifest)!;
            state.AsObject().Remove("ScopeBinding");
            await File.WriteAllTextAsync(StatePath, state.ToJsonString());
        }
        await WriteLog("current-account-log-canary");
        var paths = new List<string>();
        using var secondApi = Api(async (request, _) =>
        {
            paths.Add(request.RequestUri!.AbsolutePath);
            if (paths[^1].EndsWith("/start")) return StartResponse();
            var chunk = await ReadChunk(request);
            Assert.NotEqual(original.UploadId, chunk.UploadId);
            Assert.Equal(0, chunk.Sequence);
            var content = Encoding.UTF8.GetString(chunk.Bytes);
            Assert.DoesNotContain("old-account-log-canary", content);
            Assert.DoesNotContain("old-account-memory-canary", content);
            Assert.Contains("current-account-log-canary", content);
            return Response();
        }, panel: changed == "panel" ? "https://other-panel.example.test" : "https://panel.example.test",
            token: changed == "token" ? "new-auth-token-canary" : "old-auth-token-canary",
            device: changed == "device" ? "new-device-canary" : "old-device-canary");
        Assert.Equal("complete", manual
            ? (await Uploader().UploadNowAsync(secondApi, ["current memory"])).Status
            : (await Uploader().UploadAsync(Client(), secondApi)).Status);
        Assert.Equal(manual ? "/api/client/debug/start" : "/api/client/debug/log", paths[0]);
    }

    [Fact]
    public async Task SameManualIdentityResumesWithoutStartAndKeepsExactBytes()
    {
        await WriteLog("original manual diagnostics");
        Chunk? original = null;
        using var firstApi = Api(async (request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/start")) return StartResponse();
            original = await ReadChunk(request);
            return Response(HttpStatusCode.TooManyRequests);
        });
        Assert.Equal("retry_later", (await Uploader().UploadNowAsync(firstApi, ["original memory"])).Status);
        await WriteLog("changed diagnostics");
        using var secondApi = Api(async (request, _) =>
        {
            Assert.Equal("/api/client/debug/log", request.RequestUri!.AbsolutePath);
            var chunk = await ReadChunk(request);
            Assert.Equal(original!.UploadId, chunk.UploadId);
            Assert.Equal(original.Bytes, chunk.Bytes);
            return Response();
        });
        Assert.Equal("complete", (await Uploader().UploadNowAsync(secondApi, ["changed memory"])).Status);
    }

    [Fact]
    public async Task TwoInstancesShareLockAndManualDoesNotStartWhilePassiveUploads()
    {
        await WriteLog("some logs");
        var entered = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var starts = 0;
        using var api = Api(async (request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/start")) starts++;
            entered.SetResult();
            await release.Task;
            return Response();
        });
        var first = Uploader().UploadAsync(Client(), api);
        await entered.Task.WaitAsync(TimeSpan.FromSeconds(10));
        try
        {
            Assert.Equal("busy", (await Uploader().UploadAsync(Client(), api)).Status);
            Assert.Equal("busy", (await Uploader().UploadNowAsync(api)).Status);
            Assert.Equal(0, starts);
        }
        finally { release.SetResult(); }
        Assert.Equal("complete", (await first).Status);
    }

    [Fact]
    public async Task ManualAcquiresLockBeforeStartAndDoesNotNestLockForUpload()
    {
        var entered = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var starts = 0;
        using var api = Api(async (request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/start"))
            {
                starts++;
                entered.SetResult();
                await release.Task;
                return StartResponse();
            }
            await ReadChunk(request);
            return Response();
        });
        var manual = Uploader().UploadNowAsync(api, ["in-memory line"]);
        await entered.Task.WaitAsync(TimeSpan.FromSeconds(10));
        try
        {
            Assert.Equal("busy", (await Uploader().UploadNowAsync(api)).Status);
            Assert.Equal("busy", (await Uploader().UploadAsync(Client(), api)).Status);
            Assert.Equal(1, starts);
        }
        finally { release.SetResult(); }
        Assert.Equal("complete", (await manual).Status);
    }

    [Fact]
    public async Task MissingCapabilitySkipsPassiveAndExplicitlyRejectsManual()
    {
        var requests = new List<string>();
        using var api = Api((request, _) =>
        {
            requests.Add(request.RequestUri!.AbsolutePath);
            return Task.FromResult(StartResponse(capable: false));
        });
        Assert.Equal("unsupported_upload_protocol", (await Uploader().UploadAsync(Client(capable: false), api)).Status);
        Assert.Empty(requests);
        Assert.Equal("unsupported_upload_protocol", (await Uploader().UploadNowAsync(api)).Status);
        Assert.Equal(["/api/client/debug/start"], requests);
        Assert.False(File.Exists(StatePath));
    }

    [Fact]
    public async Task LegacyIncompleteStateCreatesFreshUploadIdAndZeroCursor()
    {
        await WriteLog("current log");
        Directory.CreateDirectory(StateDirectory);
        await File.WriteAllTextAsync(StatePath, JsonSerializer.Serialize(new
        {
            CollectionUuid = CollectionId, NextSequence = 20, Files = new { }, Completed = false,
        }));
        using var api = Api(async (request, _) =>
        {
            Assert.Equal(0, (await ReadChunk(request)).Sequence);
            return Response();
        });
        Assert.Equal("complete", (await Uploader().UploadAsync(Client(), api)).Status);
    }

    [Fact]
    public async Task UnreadableStateFailsClosedBeforeAnyRequest()
    {
        Directory.CreateDirectory(StatePath);
        using var api = Api((_, _) => throw new Xunit.Sdk.XunitException("must not send"));
        await Assert.ThrowsAnyAsync<Exception>(() => Uploader().UploadAsync(Client(), api));
    }

    [Fact]
    public async Task InitialManifestWriteFailureSendsNothing()
    {
        await WriteLog("some logs");
        Directory.CreateDirectory(StateDirectory);
        var calls = 0;
        using var api = Api((_, _) => { calls++; return Task.FromResult(StartResponse()); });
        // Snapshot enumeration happens after loading state; deny the initial atomic replacement.
        var lines = new CallbackLines(() => Directory.CreateDirectory(StatePath));
        var failure = await Record.ExceptionAsync(() => Uploader().UploadNowAsync(api, lines));
        Assert.True(failure is IOException or UnauthorizedAccessException, failure?.ToString());
        Assert.Equal(1, calls); // /start is allowed; no chunk can be sent without its manifest.
        Assert.Empty(Directory.GetFiles(StateDirectory, "*.spool"));
    }

    [Fact]
    public async Task AckPersistenceFailureStopsAndRestartReplaysAcknowledgedBytes()
    {
        await WriteLog(new string('z', DebugLogUploader.ChunkBytes + 10));
        Chunk? accepted = null;
        using var api = Api(async (request, _) =>
        {
            accepted = await ReadChunk(request);
            File.Move(StatePath, StatePath + ".saved");
            Directory.CreateDirectory(StatePath); // Force atomic replacement to fail after server ACK.
            return Response();
        });
        var failure = await Record.ExceptionAsync(() => Uploader().UploadAsync(Client(), api));
        Assert.True(failure is IOException or UnauthorizedAccessException, failure?.ToString());
        Assert.NotNull(accepted);
        Directory.Delete(StatePath);
        File.Move(StatePath + ".saved", StatePath);
        await WriteLog("logs changed after ACK");
        var resumed = new List<Chunk>();
        using var retryApi = Api(async (request, _) =>
        {
            resumed.Add(await ReadChunk(request));
            return Response();
        });
        Assert.Equal("complete", (await Uploader().UploadAsync(Client(), retryApi)).Status);
        Assert.Equal(0, resumed[0].Sequence);
        Assert.Equal(accepted.UploadId, resumed[0].UploadId);
        Assert.Equal(accepted.Bytes, resumed[0].Bytes);
    }

    [Fact]
    public async Task CancellationPropagatesAndLeavesCursorUnchanged()
    {
        await WriteLog("some logs");
        using var cts = new CancellationTokenSource();
        using var api = Api((_, ct) =>
        {
            cts.Cancel();
            ct.ThrowIfCancellationRequested();
            return Task.FromResult(Response());
        });
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => Uploader().UploadAsync(Client(), api, cts.Token));
        using var state = JsonDocument.Parse(await File.ReadAllTextAsync(StatePath));
        Assert.Equal(0, state.RootElement.GetProperty("AcknowledgedBytes").GetInt64());
        Assert.Equal(0, state.RootElement.GetProperty("NextSequence").GetInt32());
    }

    [Fact]
    public async Task HeaderFilesAndRedactionShareOneRemainingBudget()
    {
        await WriteLog("token=secret\n" + new string('x', 10000));
        using var api = Api(async (request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/start"))
                return Response(json: JsonSerializer.Serialize(new
                {
                    reference_code = "deadbeef",
                    debug_collection = new { uuid = CollectionId, status = "active", supports_upload_id = true,
                        byte_limit = 300, bytes_received = 50 },
                }));
            var chunk = await ReadChunk(request);
            Assert.Equal(250, chunk.Bytes.Length);
            Assert.DoesNotContain("top-secret", Encoding.UTF8.GetString(chunk.Bytes));
            return Response();
        });
        var result = await Uploader().UploadNowAsync(api, ["password=top-secret", new string('m', 10000)]);
        Assert.Equal("complete", result.Status);
        Assert.Equal(250, result.Bytes);
        Assert.Equal(250, new FileInfo(Directory.GetFiles(StateDirectory, "*.spool").Single()).Length);
    }

    [Fact]
    public async Task CorruptedSpoolIsNeverSentOrRebuiltUnderSameUploadId()
    {
        await WriteLog("diagnostics");
        using var api = Api((_, _) => Task.FromResult(Response(HttpStatusCode.TooManyRequests)));
        Assert.Equal("retry_later", (await Uploader().UploadAsync(Client(), api)).Status);
        var spool = Directory.GetFiles(StateDirectory, "*.spool").Single();
        await File.WriteAllTextAsync(spool, "corrupted");
        using var neverSend = Api((_, _) => throw new Xunit.Sdk.XunitException("must not send"));
        await Assert.ThrowsAsync<IOException>(() => Uploader().UploadAsync(Client(), neverSend));
    }

    public void Dispose() => Directory.Delete(_root, recursive: true);

    private sealed class CallbackLines(Action callback) : IReadOnlyList<string>
    {
        public int Count => 1;
        public string this[int index] => "in-memory line";
        public IEnumerator<string> GetEnumerator()
        {
            callback();
            yield return this[0];
        }
        System.Collections.IEnumerator System.Collections.IEnumerable.GetEnumerator() => GetEnumerator();
    }

    private sealed record Chunk(string UploadId, int Sequence, byte[] Bytes);
    private sealed class Handler(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> handler) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
            => handler(request, cancellationToken);
    }
}
