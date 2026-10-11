using System.Text.RegularExpressions;

namespace LeikwanBridge.Mac.Services;

internal static class MacSecretAccount
{
    public const string DesktopSession = "desktop-session";
    public const string RouteSelection = "connection-route-selection";

    // Upper bound probed during the one-time legacy keychain migration.
    // Existing client ids in the wild are small; 64 keeps the sweep cheap
    // while covering every realistic account name.
    public const int MaxMigrationClientIds = 64;

    public static void Validate(string name)
    {
        if (name != DesktopSession
            && name != MacConnectionProfileStore.SecretAccount
            && name != RouteSelection
            && !Regex.IsMatch(name, "^client-agent-[1-9][0-9]*$", RegexOptions.CultureInvariant))
            throw new ArgumentException("secret account invalid", nameof(name));
    }

    public static IEnumerable<string> MigrationAccountNames()
    {
        yield return DesktopSession;
        yield return MacConnectionProfileStore.SecretAccount;
        yield return RouteSelection;
        for (var id = 1; id <= MaxMigrationClientIds; id++)
            yield return $"client-agent-{id}";
    }
}
