import Foundation
import LeikwanIOSCore
import UserNotifications

/// What a module script reaches outside JavaScript: HTTP, the shared key-value store, local
/// notifications and the device's network state. One instance serves one run.
///
/// Requests are made by the process that runs the script. In the packet tunnel that means
/// they leave on the physical interface, not through the proxy, so a script that reports
/// "this device's exit address" (a firewall allowlist updater, say) sees the real one.
final class ScriptRuntimeEnvironment: NSObject, ScriptEnvironment, URLSessionDataDelegate {
    static let maxResponseBytes = 2 * 1024 * 1024
    static let maxNotificationsPerRun = 3

    private final class Pending {
        var data = Data()
        var overflow = false
        let completion: (Result<ScriptHTTPResponse, Error>) -> Void

        init(completion: @escaping (Result<ScriptHTTPResponse, Error>) -> Void) {
            self.completion = completion
        }
    }

    private let scriptKey: String
    private let store: ScriptKeyValueStore
    private let logSink: (String) -> Void
    private let lock = NSLock()
    private var session: URLSession?
    private var pending: [Int: Pending] = [:]
    private var notifications = 0

    init(scriptKey: String, store: ScriptKeyValueStore, log: @escaping (String) -> Void) {
        self.scriptKey = scriptKey
        self.store = store
        self.logSink = log
        super.init()
    }

    /// Ends the run's session (a session keeps its delegate alive until invalidated).
    func invalidate() {
        lock.lock()
        let current = session
        session = nil
        lock.unlock()
        current?.invalidateAndCancel()
    }

    // MARK: ScriptEnvironment

    func send(_ request: ScriptHTTPRequest, completion: @escaping (Result<ScriptHTTPResponse, Error>) -> Void) {
        var urlRequest = URLRequest(url: request.url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: request.timeout)
        urlRequest.httpMethod = request.method
        for (name, value) in request.headers {
            urlRequest.setValue(value, forHTTPHeaderField: name)
        }
        urlRequest.httpBody = request.body
        let task = register(urlRequest, completion: completion)
        task.resume()
        // The request timeout is an idle timeout; this bounds the whole exchange.
        DispatchQueue.global().asyncAfter(deadline: .now() + request.timeout) {
            task.cancel()
        }
    }

    func read(key: String) -> String? {
        store.read(key)
    }

    func write(_ value: String?, key: String) -> Bool {
        store.write(value, key: key)
    }

    func notify(title: String, subtitle: String, body: String) {
        guard admitNotification() else { return }
        let content = UNMutableNotificationContent()
        content.title = title
        content.subtitle = subtitle
        content.body = body
        // One identifier per script: a newer notification replaces the script's previous one.
        let request = UNNotificationRequest(identifier: "script." + scriptKey, content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request) { _ in }
    }

    func networkSnapshot() -> [String: Any] {
        NetworkSnapshot.current()
    }

    func log(_ line: String) {
        logSink(line)
    }

    // MARK: URLSession

    private func register(_ request: URLRequest, completion: @escaping (Result<ScriptHTTPResponse, Error>) -> Void) -> URLSessionDataTask {
        lock.lock()
        defer { lock.unlock() }
        let active: URLSession
        if let session {
            active = session
        } else {
            let configuration = URLSessionConfiguration.ephemeral
            configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
            configuration.urlCache = nil
            configuration.httpCookieStorage = nil
            configuration.httpShouldSetCookies = false
            configuration.waitsForConnectivity = false
            active = URLSession(configuration: configuration, delegate: self, delegateQueue: nil)
            session = active
        }
        let task = active.dataTask(with: request)
        pending[task.taskIdentifier] = Pending(completion: completion)
        return task
    }

    private func admitNotification() -> Bool {
        lock.lock()
        defer { lock.unlock() }
        notifications += 1
        return notifications <= Self.maxNotificationsPerRun
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        lock.lock()
        var overflow = false
        if let entry = pending[dataTask.taskIdentifier] {
            if entry.data.count + data.count > Self.maxResponseBytes {
                entry.overflow = true
                overflow = true
            } else {
                entry.data.append(data)
            }
        }
        lock.unlock()
        if overflow { dataTask.cancel() }
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        lock.lock()
        let entry = pending.removeValue(forKey: task.taskIdentifier)
        lock.unlock()
        guard let entry else { return }
        if entry.overflow {
            entry.completion(.failure(ScriptRuntimeError("response larger than 2 MB")))
        } else if let error {
            entry.completion(.failure(error))
        } else if let http = task.response as? HTTPURLResponse {
            var headers: [String: String] = [:]
            for (key, value) in http.allHeaderFields {
                headers[String(describing: key)] = String(describing: value)
            }
            entry.completion(.success(ScriptHTTPResponse(status: http.statusCode, headers: headers, body: entry.data)))
        } else {
            entry.completion(.failure(ScriptRuntimeError("no HTTP response")))
        }
    }
}

struct ScriptRuntimeError: LocalizedError {
    let message: String

    init(_ message: String) {
        self.message = message
    }

    var errorDescription: String? { message }
}

/// The `$network` object: the primary interface and its addresses, as Surge reports them
/// (`pdp_ip*` is cellular, `en*` Wi-Fi or wired). Wi-Fi comes first when both are up, which
/// is how iOS routes.
enum NetworkSnapshot {
    static func current() -> [String: Any] {
        let addresses = interfaceAddresses()
        func primary(_ family: Int32) -> (name: String, family: Int32, address: String)? {
            let usable = addresses.filter { entry in
                entry.family == family && !entry.address.hasPrefix("169.254.") && !entry.address.lowercased().hasPrefix("fe80")
            }
            return usable.first { $0.name.hasPrefix("en") } ?? usable.first { $0.name.hasPrefix("pdp_ip") }
        }
        func section(_ family: Int32) -> [String: Any] {
            guard let entry = primary(family) else {
                return ["primaryAddress": NSNull(), "primaryInterface": NSNull(), "primaryRouter": NSNull()]
            }
            return ["primaryAddress": entry.address, "primaryInterface": entry.name, "primaryRouter": NSNull()]
        }
        return [
            "v4": section(AF_INET),
            "v6": section(AF_INET6),
            "wifi": ["ssid": NSNull(), "bssid": NSNull()] as [String: Any],
            "dns": [String](),
        ]
    }

    private static func interfaceAddresses() -> [(name: String, family: Int32, address: String)] {
        var result: [(name: String, family: Int32, address: String)] = []
        var head: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&head) == 0 else { return [] }
        defer { freeifaddrs(head) }
        var cursor = head
        while let entry = cursor {
            cursor = entry.pointee.ifa_next
            let flags = Int32(entry.pointee.ifa_flags)
            guard flags & IFF_UP != 0, flags & IFF_LOOPBACK == 0, let address = entry.pointee.ifa_addr else { continue }
            let family = Int32(address.pointee.sa_family)
            guard family == AF_INET || family == AF_INET6 else { continue }
            var host = [CChar](repeating: 0, count: Int(NI_MAXHOST))
            let length = socklen_t(address.pointee.sa_len)
            guard getnameinfo(address, length, &host, socklen_t(host.count), nil, 0, NI_NUMERICHOST) == 0 else { continue }
            // Scoped IPv6 literals carry "%en0"; scripts expect the bare address.
            let text = String(cString: host).split(separator: "%").first.map(String.init) ?? ""
            result.append((String(cString: entry.pointee.ifa_name), family, text))
        }
        return result
    }
}

/// Runs module scripts one at a time and records each outcome for the app to show.
final class ScriptExecutor {
    private let store = ScriptKeyValueStore(url: AppGroup.scriptStoreURL)
    private let results = ScriptResultStore(url: AppGroup.scriptResultsURL)
    private let runner = SurgeScriptRunner()
    private let log: (String) -> Void
    /// nil = the script may run; otherwise the reason it may not (reported as its error).
    private let admission: () -> String?
    private let lock = NSLock()
    private var tail: Task<Void, Never>?

    init(log: @escaping (String) -> Void, admission: @escaping () -> String? = { nil }) {
        self.log = log
        self.admission = admission
    }

    /// `trigger`: "cron" / "event" / "button" / "auto-interval" / "manual".
    func run(_ script: ScriptLaunch, trigger: String, eventName: String? = nil) async -> ScriptOutcome {
        let task = enqueue {
            await self.execute(script, trigger: trigger, eventName: eventName)
        }
        return await task.value
    }

    /// Chains the run behind the previous one: one JavaScript context at a time.
    private func enqueue(_ work: @escaping () async -> ScriptOutcome) -> Task<ScriptOutcome, Never> {
        lock.lock()
        defer { lock.unlock() }
        let previous = tail
        let task = Task { () -> ScriptOutcome in
            _ = await previous?.value
            return await work()
        }
        tail = Task { _ = await task.value }
        return task
    }

    private func execute(_ script: ScriptLaunch, trigger: String, eventName: String?) async -> ScriptOutcome {
        let outcome: ScriptOutcome
        if let refusal = admission() {
            outcome = .failure(refusal)
        } else if let data = FileManager.default.contents(atPath: script.sourcePath) {
            let invocation = ScriptInvocation(
                name: script.name,
                kind: script.kind,
                source: String(decoding: data, as: UTF8.self),
                argument: script.argument,
                timeout: TimeInterval(script.timeoutSeconds),
                eventName: eventName,
                trigger: script.kind == "generic" ? trigger : nil
            )
            let sink = log
            let environment = ScriptRuntimeEnvironment(scriptKey: script.key, store: store) { line in
                sink("script \(script.name): \(line)")
            }
            outcome = await withCheckedContinuation { continuation in
                runner.run(invocation, environment: environment, hostInfo: Self.hostInfo) { result in
                    continuation.resume(returning: result)
                }
            }
            environment.invalidate()
        } else {
            outcome = .failure("script_source_missing")
        }
        results.record(outcome, key: script.key, trigger: trigger)
        if let error = outcome.error {
            log("script \(script.name) (\(trigger)) failed after \(outcome.durationMs) ms: \(error)")
        } else {
            log("script \(script.name) (\(trigger)) finished in \(outcome.durationMs) ms")
        }
        return outcome
    }

    private static var hostInfo: [String: String] {
        var info = ["language": Locale.preferredLanguages.first ?? "en"]
        if let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String {
            info["bridge-version"] = version
        }
        return info
    }
}
