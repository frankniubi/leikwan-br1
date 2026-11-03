using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// The latest <c>agent_update_request_token</c> the panel handed this process in
/// configs/pull. The panel only sends a non-empty token while an update asked for
/// from the console ("request client update") is pending, and each click mints a
/// new one, so the windowless client's update loop installs a published build only
/// when this holds a token it has not handled yet (see
/// <see cref="HeadlessAutoUpdateCoordinator"/>). Releases are published, never forced.
/// The update loop runs in the same process as the config polling that feeds this,
/// and every full pull after a restart re-sends a still-pending token, so memory is
/// enough; what has been handled is persisted in <see cref="HeadlessUpdateState"/>.
/// </summary>
public sealed class HeadlessUpdateRequestSignal
{
    /// <summary>The process-wide signal: config polling writes it, the headless update loop reads it.</summary>
    public static HeadlessUpdateRequestSignal Shared { get; } = new();

    private volatile string _token = string.Empty;

    /// <summary>The pending request token, or empty when none was seen or the last pull carried none.</summary>
    public string Current => _token;

    /// <summary>Records the token from a full pull; a pull without client metadata leaves it unchanged.</summary>
    public void Observe(ClientMeta? client)
    {
        if (client is not null)
            Observe(client.AgentUpdateRequestToken);
    }

    public void Observe(string? token) => _token = (token ?? string.Empty).Trim();
}
