import Foundation

/// Mandatory-update policy from the `client_update` object of `configs/pull`
/// (`{"client_update": {"minimum_build": 52}}`, sent only to iOS and absent when nothing is
/// configured). `minimum_build` is compared with the app's CFBundleVersion as integers.
///
/// Every doubtful case fails open (no update required): a missing or non-integer
/// `minimum_build`, a non-object `client_update`, and a build number that is not a plain integer.
public struct ClientUpdatePolicy: Equatable {
    public static let responseKey = "client_update"

    /// nil when the panel sent `client_update` without a usable `minimum_build`.
    public let minimumBuild: Int64?

    public init(minimumBuild: Int64?) {
        self.minimumBuild = minimumBuild
    }

    /// The policy in a pull response. nil when the response has no `client_update` key, so the
    /// caller keeps the policy it saw before; a policy (possibly without a minimum) otherwise.
    public static func parse(_ raw: [String: Any]) -> ClientUpdatePolicy? {
        guard let value = raw[responseKey] else { return nil }
        guard let object = JSONLenient.object(value) else { return ClientUpdatePolicy(minimumBuild: nil) }
        return ClientUpdatePolicy(minimumBuild: integer(object["minimum_build"]))
    }

    /// The policy to keep after a pull: the response's when it has one, else `previous`.
    public static func merged(previous: ClientUpdatePolicy?, raw: [String: Any]) -> ClientUpdatePolicy? {
        return parse(raw) ?? previous
    }

    /// CFBundleVersion as an integer ("52"); nil for blank, dotted ("1.2") or other text.
    public static func buildNumber(_ value: String?) -> Int64? {
        guard let text = value?.trimmingCharacters(in: .whitespacesAndNewlines), !text.isEmpty,
              text.unicodeScalars.allSatisfy({ CharacterSet.decimalDigits.contains($0) && $0.isASCII }) else {
            return nil
        }
        return Int64(text)
    }

    /// True only when both numbers are known and the running build is older than the minimum.
    public func requiresUpdate(currentBuild: String?) -> Bool {
        guard let minimum = minimumBuild, let current = ClientUpdatePolicy.buildNumber(currentBuild) else {
            return false
        }
        return current < minimum
    }

    /// A JSON integer only: booleans, fractions, strings and out-of-range numbers are rejected.
    private static func integer(_ value: Any?) -> Int64? {
        guard let number = value as? NSNumber, !JSONLenient.isBoolean(number) else { return nil }
        let double = number.doubleValue
        guard double.isFinite, double == double.rounded(), abs(double) < 9.0e15 else { return nil }
        return number.int64Value
    }
}

extension PullConfigsResponse {
    /// `client_update` of this response; nil when the key is absent (see `ClientUpdatePolicy.parse`).
    public var clientUpdatePolicy: ClientUpdatePolicy? {
        return ClientUpdatePolicy.parse(raw)
    }
}
