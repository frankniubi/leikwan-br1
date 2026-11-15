import Foundation

public struct ScriptHTTPRequest {
    public var method: String
    public var url: URL
    public var headers: [String: String]
    public var body: Data?
    public var timeout: TimeInterval

    public init(method: String, url: URL, headers: [String: String] = [:], body: Data? = nil, timeout: TimeInterval = 5) {
        self.method = method
        self.url = url
        self.headers = headers
        self.body = body
        self.timeout = timeout
    }
}

public struct ScriptHTTPResponse {
    public var status: Int
    public var headers: [String: String]
    public var body: Data

    public init(status: Int, headers: [String: String] = [:], body: Data = Data()) {
        self.status = status
        self.headers = headers
        self.body = body
    }
}

/// Everything a script can reach outside its JavaScript context. The runner calls these from
/// its own queue; `send` may complete on any queue.
public protocol ScriptEnvironment: AnyObject {
    func send(_ request: ScriptHTTPRequest, completion: @escaping (Result<ScriptHTTPResponse, Error>) -> Void)
    func read(key: String) -> String?
    func write(_ value: String?, key: String) -> Bool
    func notify(title: String, subtitle: String, body: String)
    /// The `$network` object (JSON-compatible values only).
    func networkSnapshot() -> [String: Any]
    func log(_ line: String)
}

/// One script run, as the scheduler describes it.
public struct ScriptInvocation {
    public var name: String
    /// "cron" / "event" / "generic".
    public var kind: String
    public var source: String
    public var argument: String?
    public var timeout: TimeInterval
    /// Event scripts: the event that fired.
    public var eventName: String?
    /// Panel scripts: "button" (tapped) or "auto-interval".
    public var trigger: String?

    public init(name: String, kind: String, source: String, argument: String? = nil, timeout: TimeInterval = 10, eventName: String? = nil, trigger: String? = nil) {
        self.name = name
        self.kind = kind
        self.source = source
        self.argument = argument
        self.timeout = timeout
        self.eventName = eventName
        self.trigger = trigger
    }
}

/// How a run ended.
public struct ScriptOutcome: Codable, Equatable {
    /// The script called `$done`.
    public var completed: Bool
    /// "timeout", an uncaught exception, or why the run never started; nil on success.
    public var error: String?
    /// JSON of the value passed to `$done`, when it was an object.
    public var resultJSON: String?
    public var logs: [String]
    public var durationMs: Int

    public init(completed: Bool, error: String? = nil, resultJSON: String? = nil, logs: [String] = [], durationMs: Int = 0) {
        self.completed = completed
        self.error = error
        self.resultJSON = resultJSON
        self.logs = logs
        self.durationMs = durationMs
    }

    public static func failure(_ error: String) -> ScriptOutcome {
        ScriptOutcome(completed: false, error: error)
    }
}

/// `$persistentStore`: a small string map in one JSON file, shared by the app and the tunnel
/// (each call re-reads the file, so both processes see each other's writes).
public final class ScriptKeyValueStore {
    public static let maxValueBytes = 64 * 1024
    public static let maxTotalBytes = 512 * 1024

    private let url: URL
    private let lock = NSLock()

    public init(url: URL) {
        self.url = url
    }

    public func read(_ key: String) -> String? {
        lock.lock()
        defer { lock.unlock() }
        return load()[key]
    }

    /// nil removes the key. False when the value or the store would exceed its size limit.
    @discardableResult
    public func write(_ value: String?, key: String) -> Bool {
        lock.lock()
        defer { lock.unlock() }
        var map = load()
        if let value {
            guard value.utf8.count <= Self.maxValueBytes, key.utf8.count <= 256 else { return false }
            map[key] = value
            let total = map.reduce(0) { $0 + $1.key.utf8.count + $1.value.utf8.count }
            guard total <= Self.maxTotalBytes else { return false }
        } else {
            map.removeValue(forKey: key)
        }
        guard let data = try? JSONSerialization.data(withJSONObject: map, options: [.sortedKeys]) else { return false }
        try? FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        return (try? data.write(to: url, options: .atomic)) != nil
    }

    private func load() -> [String: String] {
        guard let data = try? Data(contentsOf: url),
              let object = try? JSONSerialization.jsonObject(with: data) as? [String: String]
        else { return [:] }
        return object
    }
}

/// Last run of one script, as the app shows it.
public struct ScriptRunRecord: Codable, Equatable {
    public var finishedAt: Date
    public var completed: Bool
    public var error: String?
    public var resultJSON: String?
    public var logs: [String]
    public var durationMs: Int
    /// "cron" / "event" / "button" / "auto-interval" / "manual".
    public var trigger: String

    /// The panel fields of the `$done` value (`title`, `content`, `icon`, `icon-color`).
    public var panelFields: [String: String] {
        guard let json = resultJSON, let object = JSONLenient.parseObject(json) else { return [:] }
        var fields: [String: String] = [:]
        for key in ["title", "content", "icon", "icon-color", "style"] {
            if let value = JSONLenient.string(object[key]) { fields[key] = value }
        }
        return fields
    }
}

/// Last results by script key, in one JSON file in the app group (written by whichever
/// process ran the script, read by the app).
public final class ScriptResultStore {
    public static let maxResultBytes = 8 * 1024
    public static let maxLogLines = 40

    private let url: URL
    private let lock = NSLock()

    public init(url: URL) {
        self.url = url
    }

    public func all() -> [String: ScriptRunRecord] {
        lock.lock()
        defer { lock.unlock() }
        return load()
    }

    public func record(_ outcome: ScriptOutcome, key: String, trigger: String, now: Date = Date()) {
        lock.lock()
        defer { lock.unlock() }
        var map = load()
        var result = outcome.resultJSON
        if let text = result, text.utf8.count > Self.maxResultBytes { result = nil }
        map[key] = ScriptRunRecord(
            finishedAt: now,
            completed: outcome.completed,
            error: outcome.error,
            resultJSON: result,
            logs: Array(outcome.logs.suffix(Self.maxLogLines)),
            durationMs: outcome.durationMs,
            trigger: trigger
        )
        save(map)
    }

    /// Drops results of scripts that no longer exist.
    public func prune(keeping keys: Set<String>) {
        lock.lock()
        defer { lock.unlock() }
        let map = load()
        let kept = map.filter { keys.contains($0.key) }
        if kept.count != map.count { save(kept) }
    }

    private func load() -> [String: ScriptRunRecord] {
        guard let data = try? Data(contentsOf: url) else { return [:] }
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .secondsSince1970
        return (try? decoder.decode([String: ScriptRunRecord].self, from: data)) ?? [:]
    }

    private func save(_ map: [String: ScriptRunRecord]) {
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .secondsSince1970
        encoder.outputFormatting = [.sortedKeys]
        guard let data = try? encoder.encode(map) else { return }
        try? FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        try? data.write(to: url, options: .atomic)
    }
}
