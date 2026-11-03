import XCTest
@testable import LeikwanIOSCore

/// Expected argv vectors were derived by hand from BondForwardRunner.kt:
/// basePort = 20000 + ((12 * 37 + 0) % 15000) = 20444; endpoint i -> 20444 + i (+200 for the udp segment);
/// no QUIC legs -> WSS/TCP legs are active; join timeout = 400 (no QUIC legs -> min(max(220, 400), 400)).
final class BondPlanTests: XCTestCase {
    private let defaultTail = [
        "--chunk-size", "65536",
        "--max-window-bytes", "8388608",
        "--retransmit-ms", "700",
        "--connect-timeout-ms", "3000",
        "--join-timeout-ms", "400",
        "--interactive-chunk-bytes", "16384",
        "--latency-guard-bytes", "262144",
        "--interactive-rtt-slack-ms", "30",
        "--warmup-trigger-bytes", "65536",
        "--warmup-slice-bytes", "65536",
        "--warmup-leg-delay-ms", "80",
        "--bulk-promote-bytes", "262144",
        "--leg-cooldown-ms", "3000",
    ]

    private func forward(transport: String = "tcp", quic: Bool = false) -> ForwardContract {
        return ForwardContractParser.parse(Fixtures.bondForwardJSON(transport: transport, quic: quic))!
    }

    func testTcpPlanMatchesAndroidArgv() throws {
        let plan = try BondPlanBuilder.buildPlan(forward: forward())
        XCTAssertEqual(plan.localListenPort, 31000)
        XCTAssertEqual(plan.localLegPorts, [20444, 20445])
        XCTAssertEqual(plan.invocations.count, 3)

        XCTAssertEqual(plan.invocations[0].role, .ingress)
        XCTAssertEqual(plan.invocations[0].readyPort, 20444)
        XCTAssertEqual(plan.invocations[0].arguments, [
            "client", "--listen", "127.0.0.1:20444", "--remote", "1.2.3.4:443", "--route-key", "rk", "--auth-token", "tok1",
        ])
        XCTAssertEqual(plan.invocations[1].arguments, [
            "client", "--listen", "127.0.0.1:20445", "--remote", "edge.example.com:8443", "--route-key", "rk", "--auth-token", "tok2",
        ])

        let legs = "tcp://127.0.0.1:20444?priority=10&endpoint_id=101&node_id=7&display_host=1.2.3.4,"
            + "tcp://127.0.0.1:20445?priority=10&endpoint_id=102&display_host=edge.example.com"
        XCTAssertEqual(plan.invocations[2].role, .bond)
        XCTAssertNil(plan.invocations[2].readyPort)
        XCTAssertEqual(plan.invocations[2].arguments, [
            "client", "--protocol", "tcp", "--listen", "127.0.0.1:31000", "--legs", legs, "--session-token", "st",
        ] + defaultTail)

        XCTAssertEqual(plan.bypassHosts, ["1.2.3.4", "edge.example.com", "cdn.example.com"])
    }

    func testStrategyFileAndInjectedPorts() throws {
        let strategy = BondStrategyFile.make(directory: "/tmp/strategies/", panelId: " Panel.Example.com ", clientId: 5, forwardId: 12, strategy: .speedMax)
        // SHA-256("panel.example.com") prefix 16 = b2e4d3393cc32a1e (Python hashlib).
        XCTAssertEqual(strategy.path, "/tmp/strategies/b2e4d3393cc32a1e-5-12.strategy")
        XCTAssertEqual(strategy.contents, "speed_max\n")

        var requests: [BondLegPortRequest] = []
        let allocator = ClosurePortAllocator { request in
            requests.append(request)
            return 40000 + request.endpointIndex
        }
        let plan = try BondPlanBuilder.buildPlan(forward: forward(), listenPortOverride: 50000, strategyFile: strategy, portAllocator: allocator)
        XCTAssertEqual(requests.map { $0.androidDefaultPort }, [20444, 20445])
        XCTAssertEqual(plan.localLegPorts, [40000, 40001])
        XCTAssertEqual(plan.invocations[0].arguments[2], "127.0.0.1:40000")
        let bond = plan.invocations[2].arguments
        XCTAssertEqual(Array(bond[0..<5]), ["client", "--protocol", "tcp", "--listen", "127.0.0.1:50000"])
        XCTAssertEqual(Array(bond[7..<13]), [
            "--session-token", "st", "--strategy-file", "/tmp/strategies/b2e4d3393cc32a1e-5-12.strategy", "--default-strategy", "latency_best",
        ])
        XCTAssertTrue(bond[6].contains("tcp://127.0.0.1:40000?"))
    }

    func testTcpUdpTransportBuildsTwoSegments() throws {
        let plan = try BondPlanBuilder.buildPlan(forward: forward(transport: "tcp+udp"))
        XCTAssertEqual(plan.localLegPorts, [20444, 20445, 20644, 20645])
        XCTAssertEqual(plan.invocations.map { $0.role }, [.ingress, .ingress, .ingress, .ingress, .bond, .bond])
        XCTAssertEqual(plan.invocations[4].arguments[2], "tcp")
        XCTAssertEqual(plan.invocations[5].arguments[2], "udp")
        // udp_session_token falls back to session_token.
        XCTAssertEqual(plan.invocations[5].arguments[8], "st")
        XCTAssertTrue(plan.invocations[5].arguments[6].hasPrefix("tcp://127.0.0.1:20644?"))
    }

    func testQuicLegsReplaceStandbyTcpLegs() throws {
        let plan = try BondPlanBuilder.buildPlan(forward: forward(quic: true))
        let bond = plan.invocations.last!.arguments
        XCTAssertEqual(bond[6], "quic://1.2.3.4:443?priority=0&endpoint_id=101&node_id=7&display_host=1.2.3.4&server_name=1.2.3.4&insecure=1")
        // 2 WSS legs + 1 QUIC leg -> 35 + 2 * 20 = 75 ms.
        let index = bond.firstIndex(of: "--join-timeout-ms")!
        XCTAssertEqual(bond[index + 1], "75")
    }

    func testErrorsMirrorAndroid() {
        var raw = Fixtures.bondForwardJSON(transport: "quic")
        XCTAssertThrowsError(try BondPlanBuilder.buildPlan(forward: ForwardContractParser.parse(raw)!)) { error in
            XCTAssertEqual((error as? BondPlanError)?.message, "bond mode transport quic is not supported on iOS")
        }
        raw = Fixtures.bondForwardJSON(ingressMode: "wss_path_mux")
        XCTAssertThrowsError(try BondPlanBuilder.buildPlan(forward: ForwardContractParser.parse(raw)!))
        raw = Fixtures.bondForwardJSON()
        raw["bonding"] = ["ingress_mode": "plain_tcp_mux"] as [String: Any]
        XCTAssertThrowsError(try BondPlanBuilder.buildPlan(forward: ForwardContractParser.parse(raw)!)) { error in
            XCTAssertEqual((error as? BondPlanError)?.message, "bond mode missing bonding.session_token for tcp transport")
        }
    }

    func testRouteKeyFallsBackToPathPrefix() throws {
        var raw = Fixtures.bondForwardJSON()
        raw["bonding"] = ["ingress_mode": "plain_tcp_mux", "session_token": "st"] as [String: Any]
        let plan = try BondPlanBuilder.buildPlan(forward: ForwardContractParser.parse(raw)!)
        XCTAssertEqual(plan.invocations[0].arguments[6], "/r")
    }
}
