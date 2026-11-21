import Foundation

/// Missing/unexpanded values keep the legacy extension's default Keychain query unchanged.
/// Signed builds set the extension's ORIGINAL default access group for both host processes.
public enum LeaseKeychainScope {
    public static func explicitGroup(_ value: Any?) -> String? {
        guard let group = value as? String, !group.isEmpty, !group.contains("$(") else { return nil }
        return group
    }
}
