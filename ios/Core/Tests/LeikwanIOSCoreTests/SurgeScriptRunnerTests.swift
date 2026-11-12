#if canImport(JavaScriptCore)
import XCTest
@testable import LeikwanIOSCore

/// In-memory stand-in for the app/tunnel side of a script run.
private final class FakeScriptEnvironment: ScriptEnvironment {
    var store: [String: String] = [:]
    var requests: [ScriptHTTPRequest] = []
    var notifications: [[String]] = []
    var logs: [String] = []
    var respond: (ScriptHTTPRequest) -> Result<ScriptHTTPResponse, Error> = { _ in
        .success(ScriptHTTPResponse(status: 200, headers: ["Content-Type": "application/json"], body: Data("{}".utf8)))
    }

    func send(_ request: ScriptHTTPRequest, completion: @escaping (Result<ScriptHTTPResponse, Error>) -> Void) {
        requests.append(request)
        let result = respond(request)
        // Answer from another queue, like URLSession does.
        DispatchQueue.global().asyncAfter(deadline: .now() + 0.01) { completion(result) }
    }

    func read(key: String) -> String? { store[key] }

    func write(_ value: String?, key: String) -> Bool {
        store[key] = value
        return true
    }

    func notify(title: String, subtitle: String, body: String) {
        notifications.append([title, subtitle, body])
    }

    func networkSnapshot() -> [String: Any] {
        ["v4": ["primaryInterface": "pdp_ip0", "primaryAddress": "10.0.0.2"], "v6": ["primaryInterface": NSNull()]]
    }

    func log(_ line: String) { logs.append(line) }
}

private struct FakeError: LocalizedError {
    var errorDescription: String? { "connection lost" }
}

final class SurgeScriptRunnerTests: XCTestCase {
    private func runScript(
        _ source: String,
        environment: FakeScriptEnvironment = FakeScriptEnvironment(),
        kind: String = "generic",
        argument: String? = nil,
        timeout: TimeInterval = 5,
        eventName: String? = nil,
        trigger: String? = nil
    ) -> ScriptOutcome {
        let finished = expectation(description: "script finished")
        var outcome = ScriptOutcome.failure("not finished")
        let invocation = ScriptInvocation(name: "test", kind: kind, source: source, argument: argument, timeout: timeout, eventName: eventName, trigger: trigger)
        SurgeScriptRunner().run(invocation, environment: environment) { result in
            outcome = result
            finished.fulfill()
        }
        wait(for: [finished], timeout: timeout + 5)
        return outcome
    }

    private func result(_ outcome: ScriptOutcome) -> [String: Any] {
        outcome.resultJSON.flatMap { JSONLenient.parseObject($0) } ?? [:]
    }

    /// The flow of an allowlist updater: read the argument, POST once per token, keep state
    /// in the store, notify on change, and hand a panel object to `$done`.
    func testAllowlistStyleScript() {
        let environment = FakeScriptEnvironment()
        environment.respond = { request in
            let body = #"{"enabled":true,"currentIp":"198.51.100.7","whitelist":[{"ip":"198.51.100.0/24","slot":0}],"limit":5}"#
            return .success(ScriptHTTPResponse(status: 200, body: Data(body.utf8)))
        }
        let source = """
        var tokens = [];
        $argument.split('&').forEach(function (pair) {
        var i = pair.indexOf('=');
        if (pair.slice(0, i) === 'tokens') { tokens = decodeURIComponent(pair.slice(i + 1)).split(','); }
        });
        function call(token) {
        return new Promise(function (resolve) {
        $httpClient.post({ url: 'https://203.0.113.228/api/firewall/' + encodeURIComponent(token) + '/add', headers: { 'Content-Type': 'application/json' }, body: '', timeout: 15 }, function (error, response, data) {
        if (error) { resolve({ error: String(error) }); return; }
        var parsed = JSON.parse(data);
        parsed.status = response.status;
        resolve(parsed);
        });
        });
        }
        Promise.all(tokens.map(call)).then(function (results) {
        var cellular = ($network.v4.primaryInterface || '').indexOf('pdp_ip') === 0;
        var state = results.map(function (r) { return r.currentIp + '|' + (r.enabled ? 1 : 0); }).join(';');
        if ($persistentStore.read('fw_state') !== state) {
        $persistentStore.write(state, 'fw_state');
        $notification.post('Firewall', 'updated', state);
        }
        console.log('done', results.length, { cellular: cellular });
        $done({ title: 'ok ' + results.length + '/' + tokens.length, content: results[0].currentIp, icon: 'checkmark.shield', 'icon-color': '#34C759', cellular: cellular, surge: typeof $environment['surge-version'] });
        });
        """
        let outcome = runScript(source, environment: environment, argument: "tokens=tok_a,tok_b", trigger: "button")
        XCTAssertNil(outcome.error)
        XCTAssertTrue(outcome.completed)
        let panel = result(outcome)
        XCTAssertEqual(panel["title"] as? String, "ok 2/2")
        XCTAssertEqual(panel["content"] as? String, "198.51.100.7")
        XCTAssertEqual(panel["icon-color"] as? String, "#34C759")
        XCTAssertEqual(panel["cellular"] as? Bool, true)
        XCTAssertEqual(panel["surge"] as? String, "string")

        XCTAssertEqual(environment.requests.map { $0.method }, ["POST", "POST"])
        XCTAssertEqual(environment.requests.map { $0.url.absoluteString }.sorted(), [
            "https://203.0.113.228/api/firewall/tok_a/add",
            "https://203.0.113.228/api/firewall/tok_b/add",
        ])
        XCTAssertEqual(environment.requests[0].timeout, 15)
        XCTAssertEqual(environment.requests[0].headers["Content-Type"], "application/json")
        XCTAssertEqual(environment.store["fw_state"], "198.51.100.7|1;198.51.100.7|1")
        XCTAssertEqual(environment.notifications, [["Firewall", "updated", "198.51.100.7|1;198.51.100.7|1"]])
        XCTAssertEqual(outcome.logs, [#"done 2 {"cellular":true}"#])
    }

    func testHTTPFailureReachesTheCallbackAsAnError() {
        let environment = FakeScriptEnvironment()
        environment.respond = { _ in .failure(FakeError()) }
        let outcome = runScript("""
        $httpClient.get('https://example.com/x', function (error, response, data) {
        $done({ error: String(error), response: response === null, data: data === null });
        });
        """, environment: environment)
        XCTAssertTrue(outcome.completed)
        XCTAssertEqual(result(outcome)["error"] as? String, "connection lost")
        XCTAssertEqual(result(outcome)["response"] as? Bool, true)
        XCTAssertEqual(environment.requests.first?.method, "GET")
        XCTAssertEqual(environment.requests.first?.timeout, SurgeScriptRunner.defaultRequestTimeout)
    }

    func testObjectBodyIsSentAsJSONAndBadURLsFail() {
        let environment = FakeScriptEnvironment()
        let outcome = runScript("""
        $httpClient.post({ url: 'https://example.com/api', body: { a: 1 } }, function (error) {
        $httpClient.get({ url: 'file:///etc/passwd' }, function (second) {
        $done({ first: error === null, second: String(second) });
        });
        });
        """, environment: environment)
        XCTAssertEqual(result(outcome)["first"] as? Bool, true)
        XCTAssertEqual(result(outcome)["second"] as? String, "invalid URL")
        XCTAssertEqual(environment.requests.count, 1)
        XCTAssertEqual(environment.requests[0].body, Data(#"{"a":1}"#.utf8))
        XCTAssertEqual(environment.requests[0].headers["Content-Type"], "application/json")
    }

    func testBinaryModeReturnsBytes() {
        let environment = FakeScriptEnvironment()
        environment.respond = { _ in .success(ScriptHTTPResponse(status: 200, body: Data([0, 1, 2, 250, 255]))) }
        let outcome = runScript("""
        $httpClient.get({ url: 'https://example.com/bin', 'binary-mode': true }, function (error, response, data) {
        $done({ isBytes: data instanceof Uint8Array, length: data.length, last: data[4], sum: data[0] + data[1] + data[2] + data[3] });
        });
        """, environment: environment)
        XCTAssertEqual(result(outcome)["isBytes"] as? Bool, true)
        XCTAssertEqual(result(outcome)["length"] as? Int, 5)
        XCTAssertEqual(result(outcome)["last"] as? Int, 255)
        XCTAssertEqual(result(outcome)["sum"] as? Int, 253)
    }

    func testStoreDefaultsToOneSlotPerScriptAndNullForMissingKeys() {
        let environment = FakeScriptEnvironment()
        let outcome = runScript("""
        var missing = $persistentStore.read('nope');
        $persistentStore.write('v1');
        $done({ missing: missing === null, own: $persistentStore.read() });
        """, environment: environment)
        XCTAssertEqual(result(outcome)["missing"] as? Bool, true)
        XCTAssertEqual(result(outcome)["own"] as? String, "v1")
        XCTAssertEqual(environment.store, ["script:test": "v1"])
    }

    func testEventTriggerAndScriptGlobals() {
        let event = runScript("$done({ event: $event.name, type: $script.type, name: $script.name, argument: typeof $argument })", kind: "event", eventName: "network-changed")
        XCTAssertEqual(result(event)["event"] as? String, "network-changed")
        XCTAssertEqual(result(event)["type"] as? String, "event")
        XCTAssertEqual(result(event)["name"] as? String, "test")
        XCTAssertEqual(result(event)["argument"] as? String, "undefined")

        let panel = runScript("$done({ trigger: $trigger, system: $environment.system })", trigger: "auto-interval")
        XCTAssertEqual(result(panel)["trigger"] as? String, "auto-interval")
        XCTAssertEqual(result(panel)["system"] as? String, "iOS")
    }

    func testTimersRunInOrderAndCanBeCleared() {
        let outcome = runScript("""
        var order = [];
        var cancelled = setTimeout(function () { order.push('cancelled'); }, 5);
        clearTimeout(cancelled);
        var ticks = 0;
        var interval = setInterval(function () {
        ticks += 1;
        if (ticks === 3) { clearInterval(interval); setTimeout(function () { $done({ order: order.join(','), ticks: ticks }); }, 30); }
        }, 10);
        setTimeout(function () { order.push('b'); }, 20);
        setTimeout(function () { order.push('a'); }, 1);
        """)
        XCTAssertEqual(result(outcome)["order"] as? String, "a,b")
        XCTAssertEqual(result(outcome)["ticks"] as? Int, 3)
    }

    func testExceptionEndsTheRunWithItsMessage() {
        let outcome = runScript("var x = 1;\nnotDefined.call();")
        XCTAssertFalse(outcome.completed)
        XCTAssertTrue(outcome.error?.contains("notDefined") == true, outcome.error ?? "nil")
    }

    func testTimeoutEndsAScriptThatNeverCallsDone() {
        let environment = FakeScriptEnvironment()
        let outcome = runScript("console.log('started'); setInterval(function () {}, 50);", environment: environment, timeout: 1)
        XCTAssertFalse(outcome.completed)
        XCTAssertEqual(outcome.error, "timeout")
        XCTAssertEqual(outcome.logs, ["started"])
        XCTAssertEqual(environment.logs, ["started"])
        XCTAssertGreaterThanOrEqual(outcome.durationMs, 900)
    }

    func testDoneWithoutAnObjectHasNoResult() {
        let outcome = runScript("$done();")
        XCTAssertTrue(outcome.completed)
        XCTAssertNil(outcome.resultJSON)
        XCTAssertNil(outcome.error)
    }

    func testOversizedSourceIsRefused() {
        let outcome = runScript(String(repeating: " ", count: SurgeScriptRunner.maxSourceBytes + 1))
        XCTAssertEqual(outcome.error, "script_too_large")
    }
}

final class CronExpressionTests: XCTestCase {
    private var utc: Calendar {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "UTC")!
        return calendar
    }

    private func date(_ text: String) -> Date {
        let formatter = ISO8601DateFormatter()
        return formatter.date(from: text)!
    }

    private func next(_ expression: String, after text: String) -> Date? {
        CronExpression(expression)?.nextDate(after: date(text), calendar: utc)
    }

    func testEveryTenMinutes() {
        XCTAssertEqual(next("*/10 * * * *", after: "2026-11-13T12:03:20Z"), date("2026-11-13T12:10:00Z"))
        XCTAssertEqual(next("*/10 * * * *", after: "2026-11-13T12:10:00Z"), date("2026-11-13T12:20:00Z"))
        XCTAssertEqual(next("*/10 * * * *", after: "2026-11-13T23:55:00Z"), date("2026-11-14T00:00:00Z"))
    }

    func testFixedTimesListsRangesAndNames() {
        XCTAssertEqual(next("30 8 * * *", after: "2026-11-13T09:00:00Z"), date("2026-11-14T08:30:00Z"))
        XCTAssertEqual(next("0 9-17/4 * * *", after: "2026-11-13T09:00:00Z"), date("2026-11-13T13:00:00Z"))
        XCTAssertEqual(next("15,45 * * * *", after: "2026-11-13T09:20:00Z"), date("2026-11-13T09:45:00Z"))
        // 2026-11-13 is a Friday; the next Monday is the 16th.
        XCTAssertEqual(next("0 0 * * MON", after: "2026-11-13T09:00:00Z"), date("2026-11-16T00:00:00Z"))
        XCTAssertEqual(next("0 0 * * 7", after: "2026-11-13T09:00:00Z"), date("2026-11-15T00:00:00Z"))
        XCTAssertEqual(next("0 0 1 JAN *", after: "2026-11-13T09:00:00Z"), date("2027-01-01T00:00:00Z"))
        // Day-of-month and day-of-week both set: either matches.
        XCTAssertEqual(next("0 0 20 * 1", after: "2026-11-13T09:00:00Z"), date("2026-11-16T00:00:00Z"))
    }

    func testSixFieldFormDropsTheSecondsField() {
        XCTAssertEqual(next("30 */5 * * * *", after: "2026-11-13T12:03:20Z"), date("2026-11-13T12:05:00Z"))
    }

    func testInvalidExpressions() {
        for text in ["", "* * * *", "60 * * * *", "* 24 * * *", "*/0 * * * *", "a b c d e", "5-1 * * * *", "* * 0 * *", "* * * 13 *"] {
            XCTAssertNil(CronExpression(text), text)
        }
        XCTAssertNil(next("0 0 30 2 *", after: "2026-11-13T09:00:00Z"))
    }
}
#endif
