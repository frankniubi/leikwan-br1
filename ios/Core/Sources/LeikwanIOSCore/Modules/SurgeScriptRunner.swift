#if canImport(JavaScriptCore)
import Foundation
import JavaScriptCore

/// Runs one Surge-compatible script in a fresh JavaScriptCore context.
///
/// Provided globals: `$httpClient` (get/post/put/delete/head/options/patch), `$persistentStore`,
/// `$notification.post`, `$network`, `$environment`, `$argument`, `$script`, `$event`,
/// `$trigger`, `$done`, `console` and the timer functions. A run ends when the script calls
/// `$done`, throws, or reaches its timeout; the context and its VM are released right after,
/// so nothing stays in memory between runs (the packet tunnel has about 50 MB in total).
public final class SurgeScriptRunner {
    public static let maxSourceBytes = 1024 * 1024
    public static let maxTimeout: TimeInterval = 120
    public static let defaultRequestTimeout: TimeInterval = 5
    public static let maxRequestTimeout: TimeInterval = 60
    /// Reported to scripts that tell Surge-family clients apart by `$environment`.
    public static let compatibleSurgeVersion = "5.9.0"

    public init() {}

    /// `completion` is called exactly once, on a private queue.
    public func run(
        _ invocation: ScriptInvocation,
        environment: ScriptEnvironment,
        hostInfo: [String: String] = [:],
        completion: @escaping (ScriptOutcome) -> Void
    ) {
        guard invocation.source.utf8.count <= Self.maxSourceBytes else {
            completion(.failure("script_too_large"))
            return
        }
        ScriptRun(invocation: invocation, environment: environment, hostInfo: hostInfo, completion: completion).start()
    }
}

private final class ScriptRun {
    private let invocation: ScriptInvocation
    private var environment: ScriptEnvironment?
    private let hostInfo: [String: String]
    private var completion: ((ScriptOutcome) -> Void)?
    /// Guards `completion`: the watchdog hands over an outcome from another queue.
    private let completionLock = NSLock()
    private let queue = DispatchQueue(label: "leikwan.script.run")
    private var context: JSContext?
    /// JS functions the native side still has to call (HTTP callbacks, timers), by id. Native
    /// closures hold only the id, so dropping this map releases the context.
    private var callbacks: [Int: JSValue] = [:]
    private var repeating: [Int: Double] = [:]
    private var nextID = 1
    private var logs: [String] = []
    private var finished = false
    private let started = Date()
    /// Keeps the run alive until it has reported its outcome.
    private var retained: ScriptRun?

    init(invocation: ScriptInvocation, environment: ScriptEnvironment, hostInfo: [String: String], completion: @escaping (ScriptOutcome) -> Void) {
        self.invocation = invocation
        self.environment = environment
        self.hostInfo = hostInfo
        self.completion = completion
    }

    func start() {
        retained = self
        let timeout = min(max(invocation.timeout, 1), SurgeScriptRunner.maxTimeout)
        queue.async { self.boot() }
        queue.asyncAfter(deadline: .now() + timeout) { [weak self] in
            self?.finish(completed: false, error: "timeout", resultJSON: nil)
        }
        // JavaScriptCore has no public way to interrupt running code: a script stuck in a loop
        // holds the run queue, and the timeout above never gets its turn. The caller still
        // gets an answer; the stuck queue is abandoned.
        DispatchQueue.global().asyncAfter(deadline: .now() + timeout + 2) { [self] in
            hand(ScriptOutcome(completed: false, error: "timeout", durationMs: Int(Date().timeIntervalSince(started) * 1000)))
        }
    }

    // MARK: Context

    private func boot() {
        guard let context = JSContext(virtualMachine: JSVirtualMachine()) else {
            finish(completed: false, error: "engine_unavailable", resultJSON: nil)
            return
        }
        self.context = context
        context.exceptionHandler = { [weak self] _, exception in
            let message = exception?.toString() ?? "exception"
            self?.append("exception: \(message)")
            self?.finish(completed: false, error: message, resultJSON: nil)
        }

        let log: @convention(block) (JSValue) -> Void = { [weak self] value in
            self?.append(value.toString() ?? "")
        }
        let read: @convention(block) (JSValue) -> String? = { [weak self] key in
            guard let self else { return nil }
            return self.environment?.read(key: self.storeKey(key))
        }
        let write: @convention(block) (JSValue, JSValue) -> Bool = { [weak self] value, key in
            guard let self else { return false }
            let text: String? = value.isNull || value.isUndefined ? nil : value.toString()
            return self.environment?.write(text, key: self.storeKey(key)) ?? false
        }
        let notify: @convention(block) (JSValue, JSValue, JSValue) -> Void = { [weak self] title, subtitle, body in
            self?.environment?.notify(title: ScriptRun.text(title), subtitle: ScriptRun.text(subtitle), body: ScriptRun.text(body))
        }
        let http: @convention(block) (JSValue, JSValue, JSValue) -> Void = { [weak self] method, options, callback in
            self?.http(method: ScriptRun.text(method), options: options, callback: callback)
        }
        let timer: @convention(block) (JSValue, JSValue, JSValue) -> Int = { [weak self] function, delay, repeats in
            self?.schedule(function, milliseconds: delay.toDouble(), repeats: repeats.toBool()) ?? 0
        }
        let clear: @convention(block) (JSValue) -> Void = { [weak self] id in
            self?.cancelTimer(Int(id.toInt32()))
        }
        let done: @convention(block) (JSValue) -> Void = { [weak self] json in
            let text = json.isString ? json.toString() : nil
            self?.finish(completed: true, error: nil, resultJSON: (text ?? "").isEmpty ? nil : text)
        }
        context.setObject(unsafeBitCast(log, to: AnyObject.self), forKeyedSubscript: "__lk_log" as NSString)
        context.setObject(unsafeBitCast(read, to: AnyObject.self), forKeyedSubscript: "__lk_read" as NSString)
        context.setObject(unsafeBitCast(write, to: AnyObject.self), forKeyedSubscript: "__lk_write" as NSString)
        context.setObject(unsafeBitCast(notify, to: AnyObject.self), forKeyedSubscript: "__lk_notify" as NSString)
        context.setObject(unsafeBitCast(http, to: AnyObject.self), forKeyedSubscript: "__lk_http" as NSString)
        context.setObject(unsafeBitCast(timer, to: AnyObject.self), forKeyedSubscript: "__lk_timer" as NSString)
        context.setObject(unsafeBitCast(clear, to: AnyObject.self), forKeyedSubscript: "__lk_clear" as NSString)
        context.setObject(unsafeBitCast(done, to: AnyObject.self), forKeyedSubscript: "__lk_done" as NSString)

        var info: [String: Any] = [
            "surge-version": SurgeScriptRunner.compatibleSurgeVersion,
            "surge-build": "0",
            "system": "iOS",
        ]
        for (key, value) in hostInfo { info[key] = value }
        context.setObject(info, forKeyedSubscript: "$environment" as NSString)
        context.setObject(environment?.networkSnapshot() ?? [:], forKeyedSubscript: "$network" as NSString)
        context.setObject(
            ["name": invocation.name, "type": invocation.kind, "startTime": started] as [String: Any],
            forKeyedSubscript: "$script" as NSString
        )
        if let argument = invocation.argument {
            context.setObject(argument, forKeyedSubscript: "$argument" as NSString)
        }
        if let event = invocation.eventName {
            context.setObject(["name": event, "data": [String: Any]()] as [String: Any], forKeyedSubscript: "$event" as NSString)
        }
        if let trigger = invocation.trigger {
            context.setObject(trigger, forKeyedSubscript: "$trigger" as NSString)
        }

        context.evaluateScript(ScriptRun.prelude)
        if finished { return }
        context.evaluateScript(invocation.source)
    }

    /// `$persistentStore.read()` without a key uses one slot per script, as Surge does.
    private func storeKey(_ key: JSValue) -> String {
        if key.isNull || key.isUndefined { return "script:\(invocation.name)" }
        return key.toString() ?? ""
    }

    private static func text(_ value: JSValue) -> String {
        value.isNull || value.isUndefined ? "" : (value.toString() ?? "")
    }

    private func append(_ line: String) {
        let clipped = String(line.prefix(2000))
        if logs.count < 200 { logs.append(clipped) }
        environment?.log(clipped)
    }

    // MARK: $httpClient

    private func http(method: String, options: JSValue, callback: JSValue) {
        guard !finished, let context else { return }
        let id = register(callback)
        let dictionary = options.isObject ? (options.toDictionary() ?? [:]) : [:]
        guard let urlText = dictionary["url"] as? String, let url = ScriptRun.url(urlText) else {
            deliver(id, arguments: ["invalid URL", NSNull(), NSNull()])
            return
        }
        var request = ScriptHTTPRequest(method: method.uppercased(), url: url)
        if let headers = dictionary["headers"] as? [AnyHashable: Any] {
            for (key, value) in headers {
                request.headers[String(describing: key)] = String(describing: value)
            }
        }
        // `body` is read from the JS value: toDictionary() turns a typed array into a map.
        let body: JSValue? = options.isObject ? options.objectForKeyedSubscript("body") : nil
        if let body, body.isString {
            request.body = Data((body.toString() ?? "").utf8)
        } else if let body, body.isObject, let json = context.objectForKeyedSubscript("JSON")?.invokeMethod("stringify", withArguments: [body]), json.isString {
            request.body = Data((json.toString() ?? "").utf8)
            if !request.headers.keys.contains(where: { $0.lowercased() == "content-type" }) {
                request.headers["Content-Type"] = "application/json"
            }
        }
        if let seconds = dictionary["timeout"] as? NSNumber, seconds.doubleValue > 0 {
            request.timeout = min(seconds.doubleValue, SurgeScriptRunner.maxRequestTimeout)
        } else {
            request.timeout = SurgeScriptRunner.defaultRequestTimeout
        }
        let binary = (dictionary["binary-mode"] as? NSNumber)?.boolValue ?? false

        environment?.send(request) { [weak self] result in
            guard let self else { return }
            self.queue.async {
                switch result {
                case let .failure(error):
                    self.deliver(id, arguments: [error.localizedDescription, NSNull(), NSNull()])
                case let .success(response):
                    let meta: [String: Any] = ["status": response.status, "headers": response.headers]
                    if binary {
                        self.deliver(id, arguments: [NSNull(), meta, response.body.base64EncodedString()], decodeBinary: true)
                    } else {
                        let text = String(data: response.body, encoding: .utf8) ?? String(decoding: response.body, as: UTF8.self)
                        self.deliver(id, arguments: [NSNull(), meta, text])
                    }
                }
            }
        }
    }

    private static func url(_ text: String) -> URL? {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        let candidate = URL(string: trimmed) ?? trimmed.addingPercentEncoding(withAllowedCharacters: .urlFragmentAllowed).flatMap { URL(string: $0) }
        guard let url = candidate, let scheme = url.scheme?.lowercased(), scheme == "https" || scheme == "http", url.host != nil else { return nil }
        return url
    }

    private func register(_ function: JSValue) -> Int {
        let id = nextID
        nextID += 1
        callbacks[id] = function
        return id
    }

    private func deliver(_ id: Int, arguments: [Any], decodeBinary: Bool = false) {
        guard !finished, let function = callbacks.removeValue(forKey: id), function.isObject else { return }
        var arguments = arguments
        if decodeBinary, let context, let encoded = arguments[2] as? String,
        let bytes = context.objectForKeyedSubscript("__lk_bytes")?.call(withArguments: [encoded]) {
            arguments[2] = bytes
        }
        function.call(withArguments: arguments)
    }

    // MARK: Timers

    private func schedule(_ function: JSValue, milliseconds: Double, repeats: Bool) -> Int {
        guard !finished, function.isObject else { return 0 }
        let id = register(function)
        let delay = max(milliseconds.isFinite ? milliseconds : 0, 0) / 1000
        if repeats { repeating[id] = max(delay, 0.01) }
        arm(id, after: delay)
        return id
    }

    private func arm(_ id: Int, after delay: Double) {
        queue.asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self, !self.finished, let function = self.callbacks[id] else { return }
            if let interval = self.repeating[id] {
                function.call(withArguments: [])
                if !self.finished, self.callbacks[id] != nil { self.arm(id, after: interval) }
            } else {
                self.callbacks.removeValue(forKey: id)
                function.call(withArguments: [])
            }
        }
    }

    private func cancelTimer(_ id: Int) {
        callbacks.removeValue(forKey: id)
        repeating.removeValue(forKey: id)
    }

    // MARK: End

    /// Records the outcome now; the context is torn down on the next queue turn, because this
    /// may be running inside a call the context itself made (`$done`, an exception).
    private func finish(completed: Bool, error: String?, resultJSON: String?) {
        guard !finished else { return }
        finished = true
        let outcome = ScriptOutcome(
            completed: completed,
            error: error,
            resultJSON: resultJSON,
            logs: logs,
            durationMs: Int(Date().timeIntervalSince(started) * 1000)
        )
        queue.async {
            self.callbacks.removeAll()
            self.repeating.removeAll()
            self.context?.exceptionHandler = nil
            self.context = nil
            self.environment = nil
            self.hand(outcome)
        }
    }

    /// Delivers the first outcome only, then lets the run go (the queue turn keeps it alive
    /// until whatever is still queued has run).
    private func hand(_ outcome: ScriptOutcome) {
        completionLock.lock()
        let completion = self.completion
        self.completion = nil
        completionLock.unlock()
        guard let completion else { return }
        completion(outcome)
        queue.async { self.retained = nil }
    }

    /// The Surge API surface, built on the `__lk_*` natives.
    private static let prelude = """
    var $httpClient = {};
    ['get', 'post', 'put', 'delete', 'head', 'options', 'patch'].forEach(function (name) {
    $httpClient[name] = function (options, callback) {
    if (typeof options === 'string') { options = { url: options }; }
    __lk_http(name, options || {}, typeof callback === 'function' ? callback : function () {});
    };
    });
    var $persistentStore = {
    read: function (key) {
    var value = __lk_read(key === undefined ? null : key);
    return value === undefined || value === null ? null : value;
    },
    write: function (value, key) {
    return __lk_write(value === undefined ? null : value, key === undefined ? null : key);
    }
    };
    var $notification = {
    post: function (title, subtitle, body) { __lk_notify(title, subtitle, body); }
    };
    function __lk_format(args) {
    return Array.prototype.map.call(args, function (item) {
    if (typeof item === 'string') { return item; }
    try { var text = JSON.stringify(item); return text === undefined ? String(item) : text; } catch (e) { return String(item); }
    }).join(' ');
    }
    var console = {};
    ['log', 'info', 'warn', 'error', 'debug'].forEach(function (level) {
    console[level] = function () { __lk_log(__lk_format(arguments)); };
    });
    function setTimeout(fn, ms) { return __lk_timer(fn, ms || 0, false); }
    function setInterval(fn, ms) { return __lk_timer(fn, ms || 0, true); }
    function clearTimeout(id) { __lk_clear(id || 0); }
    function clearInterval(id) { __lk_clear(id || 0); }
    function __lk_bytes(encoded) {
    var table = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    var clean = encoded.replace(/=+$/, '');
    var out = new Uint8Array(Math.floor(clean.length * 3 / 4));
    var buffer = 0, bits = 0, index = 0;
    for (var i = 0; i < clean.length; i++) {
    buffer = (buffer << 6) | table.indexOf(clean.charAt(i));
    bits += 6;
    if (bits >= 8) { bits -= 8; out[index++] = (buffer >> bits) & 255; }
    }
    return out;
    }
    function $done(value) {
    var text = '';
    if (value !== undefined && value !== null && typeof value === 'object') {
    try { text = JSON.stringify(value) || ''; } catch (e) { text = ''; }
    }
    __lk_done(text);
    }
    """
}
#endif
