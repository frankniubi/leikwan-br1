import Foundation
import LeikwanIOSCore
import Network

/// Measures TCP connect time to host:port from the app process.
enum TCPPing {
    static func measure(host: String, port: Int, timeout: TimeInterval = 3) async -> Int? {
        guard !host.isEmpty, let nwPort = NWEndpoint.Port(rawValue: UInt16(clamping: port)), port > 0 else {
            return nil
        }
        let connection = NWConnection(host: NWEndpoint.Host(host), port: nwPort, using: .tcp)
        let started = Date()
        return await withCheckedContinuation { continuation in
            let gate = OnceGate()
            connection.stateUpdateHandler = { state in
                switch state {
                case .ready:
                    if gate.claim() {
                        continuation.resume(returning: Int(Date().timeIntervalSince(started) * 1000))
                        connection.cancel()
                    }
                case .failed, .cancelled:
                    if gate.claim() {
                        continuation.resume(returning: nil)
                    }
                default:
                    break
                }
            }
            connection.start(queue: .global(qos: .utility))
            DispatchQueue.global().asyncAfter(deadline: .now() + timeout) {
                if gate.claim() {
                    continuation.resume(returning: nil)
                    connection.cancel()
                }
            }
        }
    }

    private final class OnceGate {
        private let lock = NSLock()
        private var claimed = false

        func claim() -> Bool {
            lock.lock()
            defer { lock.unlock() }
            if claimed { return false }
            claimed = true
            return true
        }
    }
}

/// Shareable ss:// (SIP002) and socks5:// links for a node.
enum ProxyShareLink {
    static func make(_ node: ProxyNode) -> String {
        let host = node.server.contains(":") ? "[\(node.server)]" : node.server
        let fragment = node.name.addingPercentEncoding(withAllowedCharacters: .urlFragmentAllowed) ?? ""
        switch node.settings {
        case let .shadowsocks(settings):
            let userInfo = Data("\(settings.method):\(settings.password)".utf8).base64EncodedString()
                .replacingOccurrences(of: "+", with: "-")
                .replacingOccurrences(of: "/", with: "_")
                .replacingOccurrences(of: "=", with: "")
            var link = "ss://\(userInfo)@\(host):\(node.port)"
            if let plugin = settings.plugin, !plugin.isEmpty {
                let value = [plugin, settings.pluginOpts].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: ";")
                link += "/?plugin=" + (value.addingPercentEncoding(withAllowedCharacters: .alphanumerics) ?? value)
            }
            return link + (fragment.isEmpty ? "" : "#\(fragment)")
        case let .socks(settings):
            var auth = ""
            if let username = settings.username, !username.isEmpty {
                let user = username.addingPercentEncoding(withAllowedCharacters: .urlUserAllowed) ?? username
                let password = (settings.password ?? "").addingPercentEncoding(withAllowedCharacters: .urlPasswordAllowed) ?? ""
                auth = "\(user):\(password)@"
            }
            return "socks5://\(auth)\(host):\(node.port)" + (fragment.isEmpty ? "" : "#\(fragment)")
        }
    }
}
