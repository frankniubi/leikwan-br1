import XCTest
@testable import LeikwanIOSCore

/// Base64 fixtures were produced with Python's base64 module.
final class ProxyUriParserTests: XCTestCase {
    private func ss(_ node: ProxyNode?) -> ShadowsocksSettings? {
        guard let node = node, case .shadowsocks(let settings) = node.settings else { return nil }
        return settings
    }

    private func socks(_ node: ProxyNode?) -> SocksSettings? {
        guard let node = node, case .socks(let settings) = node.settings else { return nil }
        return settings
    }

    func testShadowsocksSip002Base64UserInfo() {
        // Y2hhY2hhMjAtaWV0Zi1wb2x5MTMwNTpwdw = urlsafe base64("chacha20-ietf-poly1305:pw"), unpadded.
        let node = ProxyUriParser.parse("ss://Y2hhY2hhMjAtaWV0Zi1wb2x5MTMwNTpwdw@example.com:8388#My%20Node")
        XCTAssertEqual(node?.server, "example.com")
        XCTAssertEqual(node?.port, 8388)
        XCTAssertEqual(node?.name, "My Node")
        XCTAssertEqual(node?.type, .shadowsocks)
        XCTAssertEqual(ss(node)?.method, "chacha20-ietf-poly1305")
        XCTAssertEqual(ss(node)?.password, "pw")
    }

    func testShadowsocksLegacyWholeBase64() {
        // base64("aes-256-gcm:secret@5.6.7.8:8388")
        let node = ProxyUriParser.parse("ss://YWVzLTI1Ni1nY206c2VjcmV0QDUuNi43Ljg6ODM4OA==#Legacy")
        XCTAssertEqual(node?.server, "5.6.7.8")
        XCTAssertEqual(node?.port, 8388)
        XCTAssertEqual(ss(node)?.method, "aes-256-gcm")
        XCTAssertEqual(ss(node)?.password, "secret")
        XCTAssertEqual(node?.name, "Legacy")
    }

    func testShadowsocks2022PercentEncodedKey() {
        let node = ProxyUriParser.parse("ss://2022-blake3-aes-128-gcm:YctPZ6U7xPPcU%2Bgp3u%2BOaw%3D%3D@[2001:db8::1]:443")
        XCTAssertEqual(ss(node)?.method, "2022-blake3-aes-128-gcm")
        XCTAssertEqual(ss(node)?.password, "YctPZ6U7xPPcU+gp3u+Oaw==")
        XCTAssertEqual(node?.server, "2001:db8::1")
        XCTAssertEqual(node?.port, 443)
        XCTAssertEqual(node?.name, "2001:db8::1:443")
    }

    func testShadowsocksPluginQuery() {
        let node = ProxyUriParser.parse("ss://Y2hhY2hhMjAtaWV0Zi1wb2x5MTMwNTpwdw@1.2.3.4:443/?plugin=obfs-local%3Bobfs%3Dhttp%3Bobfs-host%3Dexample.com&uot=1#P")
        XCTAssertEqual(node?.server, "1.2.3.4")
        XCTAssertEqual(ss(node)?.plugin, "obfs-local")
        XCTAssertEqual(ss(node)?.pluginOpts, "obfs=http;obfs-host=example.com")
        XCTAssertEqual(ss(node)?.udpOverTcp, true)
    }

    func testSocksVariants() {
        let plain = ProxyUriParser.parse("socks5://1.1.1.1:1080")
        XCTAssertEqual(plain?.type, .socks5)
        XCTAssertNil(socks(plain)?.username)
        XCTAssertEqual(plain?.name, "1.1.1.1:1080")

        let auth = ProxyUriParser.parse("socks5://us%40er:p%3Ass@host.example:1080#Shadow%20SOCKS")
        XCTAssertEqual(socks(auth)?.username, "us@er")
        XCTAssertEqual(socks(auth)?.password, "p:ss")
        XCTAssertEqual(auth?.name, "Shadow SOCKS")

        // dXNlcjpwYXNz = base64("user:pass") (Shadowrocket userinfo)
        let b64 = ProxyUriParser.parse("SOCKS://dXNlcjpwYXNz@9.9.9.9:7890#R")
        XCTAssertEqual(socks(b64)?.username, "user")
        XCTAssertEqual(socks(b64)?.password, "pass")
        XCTAssertEqual(b64?.port, 7890)

        // base64("user:pass@1.2.3.4:1080") as the whole authority, name from remarks=
        let whole = ProxyUriParser.parse("socks://dXNlcjpwYXNzQDEuMi4zLjQ6MTA4MA==?remarks=Home")
        XCTAssertEqual(whole?.server, "1.2.3.4")
        XCTAssertEqual(whole?.port, 1080)
        XCTAssertEqual(socks(whole)?.username, "user")
        XCTAssertEqual(socks(whole)?.password, "pass")
        XCTAssertEqual(whole?.name, "Home")

        XCTAssertNil(ProxyUriParser.parse("socks5://host.example:70000"))
    }

    func testUnsupportedSchemesAreIgnored() {
        XCTAssertNil(ProxyUriParser.parse("vmess://eyJhZGQiOiIxLjIuMy40In0="))
        XCTAssertNil(ProxyUriParser.parse("vless://uuid@1.2.3.4:443"))
        XCTAssertNil(ProxyUriParser.parse("trojan://pw@1.2.3.4:443"))
    }

    func testSubscriptionBase64Body() {
        // base64 of three lines: ss (aes-128-gcm:k1 @ 9.9.9.9:1001 #A), socks5 (#B), vmess (skipped)
        let body = "c3M6Ly9ZV1Z6TFRFeU9DMW5ZMjA2YXpFQDkuOS45Ljk6MTAwMSNBCnNvY2tzNTovL3U6cEA4LjguNC40OjEwODAjQgp2bWVzczovL2FiYwo="
        let result = ImportRouter.parseSubscriptionBody(body)
        XCTAssertEqual(result.nodes.map { $0.name }, ["A", "B"])
        XCTAssertEqual(result.skippedLines, 1)
        XCTAssertEqual(ss(result.nodes[0])?.method, "aes-128-gcm")
        XCTAssertEqual(ss(result.nodes[0])?.password, "k1")
        XCTAssertEqual(socks(result.nodes[1])?.username, "u")
    }

    func testMixedPasteKeepsSocksLines() {
        let text = """
        ss://Y2hhY2hhMjAtaWV0Zi1wb2x5MTMwNTpwdw@1.2.3.4:443#S
        socks5://user:pass@5.6.7.8:1080#K
        https://sub.example.com/link
        vmess://abc
        random words
        """
        let result = ImportRouter.process(text)
        XCTAssertEqual(result.nodes.map { $0.name }, ["S", "K"])
        XCTAssertEqual(result.subscriptionURLs, ["https://sub.example.com/link"])
        XCTAssertEqual(result.skippedLines, 2)
        XCTAssertTrue(result.isMixed)
        XCTAssertTrue(ImportRouter.looksImportable("socks://x"))
        XCTAssertTrue(ImportRouter.process("   ").isEmpty)
    }

    func testBase64PasteFallsBackToSubscriptionParsing() {
        let result = ImportRouter.process("YWVzLTI1Ni1nY206c2VjcmV0QDUuNi43Ljg6ODM4OA==")
        XCTAssertTrue(result.nodes.isEmpty, "a bare legacy body without ss:// is not a node")
        let wrapped = ImportRouter.process("c3M6Ly9ZV1Z6TFRFeU9DMW5ZMjA2YXpFQDkuOS45Ljk6MTAwMSNBCnNvY2tzNTovL3U6cEA4LjguNC40OjEwODAjQgp2bWVzczovL2FiYwo=")
        XCTAssertEqual(wrapped.nodes.count, 2)
    }
}
