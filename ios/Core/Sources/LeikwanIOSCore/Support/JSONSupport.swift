import Foundation

/// Lenient readers for `JSONSerialization` output (`[String: Any]`), mirroring the
/// Gson helpers used by the Android client (`stringOrNull`, `intOrNull`, ...).
public enum JSONLenient {
    /// True when the value is a JSON boolean (CFBoolean-backed NSNumber).
    public static func isBoolean(_ value: Any?) -> Bool {
        guard let number = value as? NSNumber else { return false }
        return CFGetTypeID(number) == CFBooleanGetTypeID()
    }

    /// Primitive -> trimmed non-empty string. Numbers and booleans are stringified (Gson `asString`).
    public static func string(_ value: Any?) -> String? {
        guard let value = value, !(value is NSNull) else { return nil }
        var text: String?
        if let string = value as? String {
            text = string
        } else if let number = value as? NSNumber {
            if isBoolean(number) {
                text = number.boolValue ? "true" : "false"
            } else {
                text = number.stringValue
            }
        }
        guard let raw = text else { return nil }
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? nil : trimmed
    }

    public static func stringOrEmpty(_ value: Any?) -> String {
        return string(value) ?? ""
    }

    /// Number (truncated) or numeric string. Booleans are rejected.
    public static func int64(_ value: Any?) -> Int64? {
        guard let value = value, !(value is NSNull) else { return nil }
        if let number = value as? NSNumber {
            if isBoolean(number) { return nil }
            let double = number.doubleValue
            if double.isNaN || double.isInfinite { return nil }
            if double > 9.2e18 || double < -9.2e18 { return nil }
            return number.int64Value
        }
        if let string = value as? String {
            return Int64(string.trimmingCharacters(in: .whitespacesAndNewlines))
        }
        return nil
    }

    public static func int(_ value: Any?) -> Int? {
        guard let wide = int64(value) else { return nil }
        if wide > Int64(Int32.max) || wide < Int64(Int32.min) { return nil }
        return Int(wide)
    }

    /// JSON bool, number (non-zero = true) or "1/true/yes/on" / "0/false/no/off" strings.
    public static func bool(_ value: Any?) -> Bool? {
        guard let value = value, !(value is NSNull) else { return nil }
        if let number = value as? NSNumber {
            if isBoolean(number) { return number.boolValue }
            return number.doubleValue != 0
        }
        if let string = value as? String {
            switch string.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
            case "1", "true", "yes", "on": return true
            case "0", "false", "no", "off": return false
            default: return nil
            }
        }
        return nil
    }

    public static func object(_ value: Any?) -> [String: Any]? {
        return value as? [String: Any]
    }

    public static func array(_ value: Any?) -> [Any] {
        return (value as? [Any]) ?? []
    }

    /// Strict Ultra reader: JSON primitive only, trimmed, non-empty.
    public static func requiredString(_ value: Any?) -> String? {
        guard let value = value else { return nil }
        if value is NSNull || value is [Any] || value is [String: Any] { return nil }
        return string(value)
    }

    /// Strict Ultra reader (Gson `asLong` on a primitive): numbers or untrimmed numeric strings.
    public static func strictInt64(_ value: Any?) -> Int64? {
        guard let value = value, !(value is NSNull) else { return nil }
        if let number = value as? NSNumber {
            if isBoolean(number) { return nil }
            let double = number.doubleValue
            if double.isNaN || double.isInfinite || double > 9.2e18 || double < -9.2e18 { return nil }
            return number.int64Value
        }
        if let string = value as? String {
            return Int64(string)
        }
        return nil
    }

    /// Parses a JSON document whose root must be an object.
    public static func parseObject(_ data: Data) -> [String: Any]? {
        guard let root = try? JSONSerialization.jsonObject(with: data, options: []) else { return nil }
        return root as? [String: Any]
    }

    public static func parseObject(_ text: String) -> [String: Any]? {
        return parseObject(Data(text.utf8))
    }
}

/// Deterministic JSON output (sorted keys, unescaped slashes).
public enum CanonicalJSON {
    public static func data(_ object: Any) throws -> Data {
        return try JSONSerialization.data(withJSONObject: object, options: [.sortedKeys, .withoutEscapingSlashes])
    }

    public static func string(_ object: Any) throws -> String {
        let bytes = try data(object)
        return String(decoding: bytes, as: UTF8.self)
    }
}

/// ISO-8601 instants as accepted by `java.time.Instant.parse` (with optional fraction and offset).
public enum ISOInstant {
    public static func parse(_ value: String) -> Date? {
        let text = value.trimmingCharacters(in: .whitespacesAndNewlines)
        let pattern = "^(\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2})(\\.\\d{1,9})?(Z|z|[+-]\\d{2}:\\d{2})$"
        guard let regex = try? NSRegularExpression(pattern: pattern, options: []) else { return nil }
        let ns = text as NSString
        guard let match = regex.firstMatch(in: text, options: [], range: NSRange(location: 0, length: ns.length)) else {
            return nil
        }
        let base = ns.substring(with: match.range(at: 1))
        var zone = ns.substring(with: match.range(at: 3))
        if zone == "z" { zone = "Z" }
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        guard let whole = formatter.date(from: base + zone) else { return nil }
        let fractionRange = match.range(at: 2)
        if fractionRange.location != NSNotFound, fractionRange.length > 0 {
            let fraction = Double("0" + ns.substring(with: fractionRange)) ?? 0
            return whole.addingTimeInterval(fraction)
        }
        return whole
    }

    /// `Instant.toString()`-like rendering in UTC (no fraction when whole seconds, else milliseconds).
    public static func format(_ date: Date) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        let seconds = date.timeIntervalSince1970
        let wholeSeconds = seconds.rounded(.down)
        let millis = Int(((seconds - wholeSeconds) * 1000).rounded())
        if millis == 0 || millis >= 1000 {
            formatter.formatOptions = [.withInternetDateTime]
            return formatter.string(from: Date(timeIntervalSince1970: seconds.rounded()))
        }
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter.string(from: date)
    }
}
