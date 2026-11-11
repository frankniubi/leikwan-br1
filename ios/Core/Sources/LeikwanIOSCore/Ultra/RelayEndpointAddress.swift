import Foundation

/// "ip:port" / "[v6]:port" as the Ultra lease managers report the relay entry in use.
public struct RelayEndpointAddress: Equatable {
    public let host: String
    public let port: UInt16

    public init(host: String, port: UInt16) {
        self.host = host
        self.port = port
    }

    public static func parse(_ text: String) -> RelayEndpointAddress? {
        let trimmed = text.trimmingCharacters(in: .whitespaces)
        let host: Substring
        let portText: Substring
        if trimmed.hasPrefix("[") {
            guard let close = trimmed.firstIndex(of: "]") else { return nil }
            host = trimmed[trimmed.index(after: trimmed.startIndex)..<close]
            let rest = trimmed[trimmed.index(after: close)...]
            guard rest.hasPrefix(":") else { return nil }
            portText = rest.dropFirst()
        } else {
            guard let colon = trimmed.lastIndex(of: ":"), trimmed.firstIndex(of: ":") == colon else { return nil }
            host = trimmed[..<colon]
            portText = trimmed[trimmed.index(after: colon)...]
        }
        guard !host.isEmpty, let port = UInt16(portText), port > 0 else { return nil }
        return RelayEndpointAddress(host: String(host), port: port)
    }

    /// The endpoint to probe: the lowest forward id's, so the number stays on one relay.
    public static func preferred(_ endpoints: [Int64: String]) -> RelayEndpointAddress? {
        for key in endpoints.keys.sorted() {
            if let text = endpoints[key], let address = parse(text) { return address }
        }
        return nil
    }
}
