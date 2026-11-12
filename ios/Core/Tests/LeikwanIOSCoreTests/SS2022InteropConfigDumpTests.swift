import Foundation
import XCTest
@testable import LeikwanIOSCore

/// Dumps the sing-box client config the SS2022 interop workflow
/// (.github/workflows/ss2022-interop.yml) runs against real servers:
///
/// `ios-ss2022-overlong.json`: a node whose stored iPSK decodes to 47 bytes. sing-box servers
/// hash over-long keys (SHA256 truncated) while the strict sing-box client library rejects them
/// with `bad key length`, so the builder must emit the normalized key
/// base64(SHA256(decoded)[:32]) for the handshake to succeed.
///
/// tools/ss2022-interop/harness.py rewrites only the inbound and the server address (never the
/// password) and proves a real connection round-trips.
final class SS2022InteropConfigDumpTests: XCTestCase {
    func testDumpSS2022InteropConfigWithOverLongKey() throws {
        let node = ProxyNode(
            id: 60, name: "ss2022-overlong", server: "127.0.0.1", port: 18388,
            settings: .shadowsocks(ShadowsocksSettings(
                method: "2022-blake3-aes-256-gcm",
                password: "VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM:dXNlci11UFNLLTAxMjM0NTY3ODlhYmNkZWYwMTIzNDU"
            ))
        )

        let settings = SingboxSettings(logLevel: "debug", ruleSetDirectory: "/__RULESETS__")
        let builder = SingboxConfigBuilder(settings: settings, safePathRegistry: Fixtures.safePaths)
        let compiled = try builder.build(proxyNodes: [node], runtime: nil, connectionMode: .direct)

        let config = Fixtures.parseJSON(compiled.configJSON)
        let outbounds = (config["outbounds"] as? [[String: Any]]) ?? []
        let proxy = outbounds.first { ($0["tag"] as? String) == "proxy-60" }
        XCTAssertEqual(proxy?["type"] as? String, "shadowsocks")
        XCTAssertEqual(proxy?["method"] as? String, "2022-blake3-aes-256-gcm")
        // The stored iPSK (47 decoded bytes) must come out as base64(SHA256(decoded)[:32]),
        // padded; the exact-length uPSK passes through unchanged.
        XCTAssertEqual(
            proxy?["password"] as? String,
            "ahzdXvlNZcEOt6YAg8YPZa5ouTl+KJRvtXPaH1ayyAA=:dXNlci11UFNLLTAxMjM0NTY3ODlhYmNkZWYwMTIzNDU="
        )

        let outDir: URL
        if let env = ProcessInfo.processInfo.environment["SS2022_INTEROP_OUT"], !env.isEmpty {
            outDir = URL(fileURLWithPath: env, isDirectory: true)
        } else {
            outDir = FileManager.default.temporaryDirectory.appendingPathComponent("ss2022-interop-\(UUID().uuidString)", isDirectory: true)
        }
        try FileManager.default.createDirectory(at: outDir, withIntermediateDirectories: true)
        let target = outDir.appendingPathComponent("ios-ss2022-overlong.json")
        try compiled.configJSON.write(to: target, atomically: true, encoding: .utf8)
        print("ss2022-interop: wrote \(target.path)")
    }
}
