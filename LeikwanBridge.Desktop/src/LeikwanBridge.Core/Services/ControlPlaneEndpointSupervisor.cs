using System.Net;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Keeps the desktop control-plane endpoint sticky while allowing transport
/// failures to fail over to a configured mirror. HTTP API contracts remain
/// owned by the caller; this class only chooses where a request is sent.
/// </summary>
public sealed class ControlPlaneEndpointSupervisor
{
    private static readonly HttpClient SharedHttpClient = new();
    private static readonly TimeSpan[] Backoff =
    [
        TimeSpan.FromMilliseconds(50),
        TimeSpan.FromMilliseconds(150),
        TimeSpan.FromMilliseconds(500),
        TimeSpan.FromSeconds(2),
    ];

    private readonly IReadOnlyList<Uri> _endpoints;
    private readonly Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> _sendAsync;
    private readonly object _gate = new();
    private int _currentIndex;
    private int[] _failureCounts;
    private DateTimeOffset _primaryProbeAfterUtc;

    public ControlPlaneEndpointSupervisor(string primary, IReadOnlyList<string> fallback)
        : this(primary, fallback, static (request, ct) => SharedHttpClient.SendAsync(request, ct))
    {
    }

    public ControlPlaneEndpointSupervisor(
        string primary,
        IReadOnlyList<string> fallback,
        Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> sendAsync)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(primary);
        ArgumentNullException.ThrowIfNull(fallback);
        ArgumentNullException.ThrowIfNull(sendAsync);

        var endpoints = new List<Uri>();
        AddEndpoint(endpoints, primary);
        foreach (var mirror in fallback)
            AddEndpoint(endpoints, mirror);
        if (endpoints.Count == 0)
            throw new ArgumentException("At least one control-plane endpoint is required.", nameof(primary));

        _endpoints = endpoints;
        _failureCounts = new int[endpoints.Count];
        _sendAsync = sendAsync;
    }

    public Uri CurrentEndpoint
    {
        get
        {
            lock (_gate)
                return _endpoints[_currentIndex];
        }
    }

    public async Task<T> ExecuteAsync<T>(
        HttpMethod method,
        string relativePath,
        Func<Uri, HttpRequestMessage> requestFactory,
        Func<HttpResponseMessage, CancellationToken, Task<T>> responseReader,
        CancellationToken ct)
    {
        ArgumentNullException.ThrowIfNull(method);
        ArgumentException.ThrowIfNullOrWhiteSpace(relativePath);
        ArgumentNullException.ThrowIfNull(requestFactory);
        ArgumentNullException.ThrowIfNull(responseReader);

        var candidates = GetCandidateIndexes();
        Exception? lastError = null;
        for (var attempt = 0; attempt < candidates.Count; attempt++)
        {
            ct.ThrowIfCancellationRequested();
            var index = candidates[attempt];
            var endpoint = _endpoints[index];
            if (attempt > 0)
                await Task.Delay(Backoff[Math.Min(attempt - 1, Backoff.Length - 1)], ct).ConfigureAwait(false);

            using var request = requestFactory(endpoint);
            HttpResponseMessage? response = null;
            try
            {
                response = await _sendAsync(request, ct).ConfigureAwait(false);
                if (IsBusinessResponse(response.StatusCode))
                {
                    RecordSuccess(endpoint);
                    return await responseReader(response, ct).ConfigureAwait(false);
                }

                lastError = new HttpRequestException(
                    $"Control-plane endpoint returned transient HTTP {(int)response.StatusCode}.",
                    null,
                    response.StatusCode);
                if (method != HttpMethod.Get && method != HttpMethod.Head)
                {
                    RecordSuccess(endpoint);
                    return await responseReader(response, ct).ConfigureAwait(false);
                }
                response.Dispose();
                RecordTransportFailure(endpoint);
            }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested)
            {
                lastError = new TimeoutException("Control-plane request timed out.");
                response?.Dispose();
                RecordTransportFailure(endpoint);
            }
            catch (HttpRequestException ex)
            {
                lastError = ex;
                response?.Dispose();
                RecordTransportFailure(endpoint);
            }
        }

        throw lastError ?? new HttpRequestException("No control-plane endpoint was available.");
    }

    public void RecordSuccess(Uri endpoint)
    {
        var index = IndexOf(endpoint);
        if (index < 0)
            return;
        lock (_gate)
        {
            _failureCounts[index] = 0;
            _currentIndex = index;
            if (index == 0)
                _primaryProbeAfterUtc = DateTimeOffset.UtcNow;
        }
    }

    public void RecordTransportFailure(Uri endpoint)
    {
        var index = IndexOf(endpoint);
        if (index < 0)
            return;
        lock (_gate)
        {
            var failures = Math.Min(++_failureCounts[index], Backoff.Length);
            var cooldown = Backoff[Math.Max(0, failures - 1)];
            if (index == 0)
                _primaryProbeAfterUtc = DateTimeOffset.UtcNow + cooldown;

            if (_endpoints.Count > 1)
            {
                if (index == _currentIndex)
                    _currentIndex = index == 0 ? 1 : 0;
                else if (_currentIndex == 0 && index != 0)
                    _currentIndex = index;
            }
        }
    }

    public static bool IsBusinessResponse(HttpStatusCode statusCode)
        => statusCode is not (HttpStatusCode.BadGateway
            or HttpStatusCode.ServiceUnavailable
            or HttpStatusCode.GatewayTimeout)
            && (int)statusCode != 530;

    private List<int> GetCandidateIndexes()
    {
        lock (_gate)
        {
            var candidates = new List<int>(_endpoints.Count);
            if (_currentIndex != 0
                && DateTimeOffset.UtcNow >= _primaryProbeAfterUtc)
                candidates.Add(0);
            if (!candidates.Contains(_currentIndex))
                candidates.Add(_currentIndex);
            for (var i = 0; i < _endpoints.Count; i++)
            {
                if (!candidates.Contains(i))
                    candidates.Add(i);
            }
            return candidates;
        }
    }

    private int IndexOf(Uri endpoint)
    {
        for (var i = 0; i < _endpoints.Count; i++)
        {
            if (Uri.Compare(_endpoints[i], endpoint, UriComponents.SchemeAndServer,
                    UriFormat.Unescaped, StringComparison.OrdinalIgnoreCase) == 0)
                return i;
        }
        return -1;
    }

    private static void AddEndpoint(List<Uri> endpoints, string value)
    {
        if (!Uri.TryCreate(value.Trim().TrimEnd('/'), UriKind.Absolute, out var uri)
            || !string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)
                && !string.Equals(uri.Scheme, Uri.UriSchemeHttp, StringComparison.OrdinalIgnoreCase))
            throw new ArgumentException($"Invalid control-plane endpoint: {value}", nameof(value));
        if (!endpoints.Any(existing => Uri.Compare(existing, uri, UriComponents.SchemeAndServer,
                UriFormat.Unescaped, StringComparison.OrdinalIgnoreCase) == 0))
            endpoints.Add(uri);
    }
}
