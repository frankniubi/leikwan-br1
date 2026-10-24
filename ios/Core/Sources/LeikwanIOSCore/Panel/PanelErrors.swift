import Foundation

/// Port of `session/DeviceControlModels.kt` `DeviceCommand`.
public struct DeviceCommand: Equatable {
    public var version: Int64
    public var action: String
    public var reason: String

    public init(version: Int64 = 0, action: String = "", reason: String = "") {
        self.version = version
        self.action = action
        self.reason = reason
    }
}

public enum PanelError: Error {
    /// HTTP 429 `client_online_limit_reached`: recoverable, retry every 60 s, keep the data plane.
    case deviceLimitReached(limit: Int?, active: Int?)
    /// 403/409 `device_kicked|device_banned|device_untrusted`: stop and sign out locally.
    case terminalControl(code: String, command: DeviceCommand?)
    /// Any other non-2xx answer (message extracted with `PanelErrorParser`).
    case http(status: Int, message: String)
    /// No HTTP answer at all (DNS/TCP/TLS/timeout). Eligible for endpoint failover.
    case transport(Error)
    /// 2xx but the body is not what the contract says.
    case invalidResponse(String)

    public static let deviceLimitCode = "client_online_limit_reached"
    public static let terminalCodes: Set<String> = ["device_kicked", "device_banned", "device_untrusted"]

    public var isTransport: Bool {
        if case .transport = self { return true }
        return false
    }

    /// The panel itself never answered: a network failure, or a CDN edge reporting that it could
    /// not reach the origin (Cloudflare 52x, or a gateway error). Another address of the same panel
    /// can still answer. An answer from the panel application (400, 401, 500, ...) is not eligible:
    /// the other address reaches the same application.
    public var isFailoverEligible: Bool {
        switch self {
        case .transport:
            return true
        case .http(let status, _):
            return PanelError.edgeFailureStatuses.contains(status)
        default:
            return false
        }
    }

    /// 502-504 gateway errors and Cloudflare's 520-526 (origin unreachable, timed out, or TLS).
    public static let edgeFailureStatuses: Set<Int> = Set([502, 503, 504] + Array(520...526))
}

extension PanelError: CustomStringConvertible {
    public var description: String {
        switch self {
        case .deviceLimitReached(let limit, let active):
            let limitText = limit.map { String($0) } ?? "?"
            let activeText = active.map { String($0) } ?? "?"
            return PanelError.deviceLimitCode + " (limit: " + limitText + ", active: " + activeText + ")"
        case .terminalControl(let code, _):
            return code
        case .http(_, let message):
            return message
        case .transport(let error):
            return "transport: \(error.localizedDescription)"
        case .invalidResponse(let message):
            return message
        }
    }
}

/// Port of `api/PanelErrorParser.kt` + `PanelControlInterceptor` semantics.
public enum PanelErrorParser {
    /// 429 + `error == "client_online_limit_reached"` (string or `{code}`) -> device limit.
    /// Counts come from flat fields first, then `details.*`.
    public static func deviceLimit(status: Int, body: Data?) -> PanelError? {
        guard status == 429, let body = body, !body.isEmpty, let obj = JSONLenient.parseObject(body) else { return nil }
        let errorCode: String?
        if let errorObj = obj["error"] as? [String: Any] {
            errorCode = primitiveString(errorObj["code"])
        } else {
            errorCode = primitiveString(obj["error"])
        }
        guard errorCode == PanelError.deviceLimitCode else { return nil }
        let details = obj["details"] as? [String: Any]
        return .deviceLimitReached(
            limit: countValue(obj, details, "online_device_limit"),
            active: countValue(obj, details, "active_online_devices")
        )
    }

    /// 403/409 with a terminal device control error.
    public static func terminalControl(status: Int, body: Data?) -> PanelError? {
        guard status == 403 || status == 409, let body = body, let obj = JSONLenient.parseObject(body) else { return nil }
        guard let code = obj["error"] as? String, PanelError.terminalCodes.contains(code) else { return nil }
        var command: DeviceCommand?
        if let commandObj = obj["command"] as? [String: Any] {
            command = DeviceCommand(
                version: JSONLenient.int64(commandObj["version"]) ?? 0,
                action: (commandObj["action"] as? String) ?? "",
                reason: (commandObj["reason"] as? String) ?? ""
            )
        }
        return .terminalControl(code: code, command: command)
    }

    /// Maps any non-2xx answer: device limit, terminal control, else `.http` with an extracted message.
    public static func error(status: Int, reason: String?, body: Data?) -> PanelError {
        if let limit = deviceLimit(status: status, body: body) { return limit }
        if let terminal = terminalControl(status: status, body: body) { return terminal }
        return .http(status: status, message: messageFromHttp(status: status, reason: reason, body: body))
    }

    public static func messageFromHttp(status: Int, reason: String?, body: Data?) -> String {
        if let message = messageFromBody(body) { return message }
        var text = "HTTP \(status)"
        if let reason = reason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
            text += " " + reason
        }
        return text
    }

    public static func messageFromBody(_ body: Data?) -> String? {
        guard let body = body, !body.isEmpty, let obj = JSONLenient.parseObject(body) else { return nil }
        let message = messageFromElement(obj["error"]) ?? primitiveString(obj["message"])
        guard let result = message, !result.trimmingCharacters(in: .whitespaces).isEmpty else { return nil }
        return result
    }

    static func messageFromElement(_ element: Any?) -> String? {
        guard let element = element, !(element is NSNull) else { return nil }
        if let obj = element as? [String: Any] {
            let code = primitiveString(obj["code"])
            let details = obj["details"] as? [String: Any]
            let reason = primitiveString(details?["reason"])
            let message = primitiveString(obj["message"])
            var parts: [String] = []
            if let code = code { parts.append(code) }
            if let second = reason ?? message, !parts.contains(second) { parts.append(second) }
            let joined = parts.joined(separator: ": ")
            return joined.isEmpty ? nil : joined
        }
        if element is [Any] { return nil }
        return primitiveString(element)
    }

    static func primitiveString(_ value: Any?) -> String? {
        guard let value = value else { return nil }
        if value is [Any] || value is [String: Any] { return nil }
        return JSONLenient.string(value)
    }

    static func countValue(_ obj: [String: Any], _ details: [String: Any]?, _ key: String) -> Int? {
        return intValue(obj[key]) ?? intValue(details?[key])
    }

    static func intValue(_ value: Any?) -> Int? {
        guard let text = primitiveString(value), let number = Int(text), number >= 0 else { return nil }
        return number
    }
}
