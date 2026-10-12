import Foundation
import Security

/// Minimal generic-password Keychain wrapper for the agent token and the
/// stable install id.
enum Keychain {
    private static let service = "com.leikwan.bridge.ios"

    static func string(_ account: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data
        else {
            return nil
        }
        return String(data: data, encoding: .utf8)
    }

    @discardableResult
    static func set(_ value: String?, for account: String) -> Bool {
        let base: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        SecItemDelete(base as CFDictionary)
        guard let value else { return true }
        var attributes = base
        attributes[kSecValueData as String] = Data(value.utf8)
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        return SecItemAdd(attributes as CFDictionary, nil) == errSecSuccess
    }

    /// Stable random id for this installation; feeds the device id so the
    /// panel sees the same device across launches (and app reinstalls, since
    /// Keychain items survive deletion of the app).
    static func installID() -> String {
        if let existing = string("install_id"), !existing.isEmpty {
            return existing
        }
        let fresh = UUID().uuidString.lowercased()
        set(fresh, for: "install_id")
        return fresh
    }
}
