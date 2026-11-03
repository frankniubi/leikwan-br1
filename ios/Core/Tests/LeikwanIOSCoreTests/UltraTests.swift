import XCTest
@testable import LeikwanIOSCore

final class UltraTests: XCTestCase {
    private func policy() -> UltraPolicy {
        return UltraPolicy.from(Fixtures.ultraForward(), now: Fixtures.now)!
    }

    func testPolicyParsesAndNormalises() {
        let p = policy()
        XCTAssertEqual(p.forwardId, 12)
        XCTAssertEqual(p.transport, "tcp")
        XCTAssertEqual(p.quotaRemainingBytes, 5000)
        XCTAssertEqual(p.rateLimitMbps, 200)
        XCTAssertEqual(p.targetDigest, Fixtures.targetDigest)
        XCTAssertEqual(UltraPolicy.targetDigest("  1.2.3.4:443 "), Fixtures.targetDigest)
        XCTAssertNil(p.tcpFirstFlight)
    }

    func testPolicyRejections() {
        XCTAssertNil(UltraPolicy.from(Fixtures.ultraForward(access: nil), now: Fixtures.now))
        XCTAssertNil(UltraPolicy.from(Fixtures.ultraForward(access: Fixtures.ultraAccess(forwardId: 13)), now: Fixtures.now))
        XCTAssertNil(UltraPolicy.from(Fixtures.ultraForward(access: Fixtures.ultraAccess(expiresIn: 3700)), now: Fixtures.now), "beyond 3600 s TTL")
        XCTAssertNil(UltraPolicy.from(Fixtures.ultraForward(), now: Fixtures.now.addingTimeInterval(700)), "expired")
        var access = Fixtures.ultraAccess()
        access["target_digest"] = String(repeating: "c", count: 64)
        XCTAssertNil(UltraPolicy.from(Fixtures.ultraForward(access: access), now: Fixtures.now))
        access = Fixtures.ultraAccess()
        access["transport"] = "quic"
        XCTAssertNil(UltraPolicy.from(Fixtures.ultraForward(access: access), now: Fixtures.now))
        var inactive = Fixtures.ultraForward()
        inactive.serviceStatus = "suspended"
        XCTAssertNil(UltraPolicy.from(inactive, now: Fixtures.now))
    }

    func testTcpFirstFlight() {
        var access = Fixtures.ultraAccess()
        let hash = String(repeating: "d", count: 64)
        access["tcp_first_flight"] = [
            "target": Fixtures.target,
            "policy_generation": Fixtures.generation,
            "expires_at": Fixtures.iso(Fixtures.now.addingTimeInterval(60)),
            "prefix_bytes": 4,
            "delay_ms": 30,
            "candidate_hashes": [hash.uppercased(), hash, "short"],
        ] as [String: Any]
        let p = UltraPolicy.from(Fixtures.ultraForward(access: access), now: Fixtures.now)!
        XCTAssertEqual(p.tcpFirstFlight, UltraTcpFirstFlight(target: Fixtures.target, candidateHashes: [hash], prefixBytes: 4, delayMillis: 30))
    }

    func testRuntimeConfigKeysMatchAndroid() throws {
        let paths = UltraRuntimePaths.make(directory: "/var/ultra", forwardId: 12, policyGeneration: Fixtures.generation)
        XCTAssertEqual(paths.configPath, "/var/ultra/forward-12-aaaaaaaaaaaa.json")
        XCTAssertEqual(paths.controlSocketPath, "/var/ultra/forward-12-aaaaaaaaaaaa.control.sock")
        let plan = try UltraRuntimeConfigBuilder.buildPlan(forward: Fixtures.ultraForward(), policy: policy(), paths: paths)
        XCTAssertEqual(plan.invocation.role, .ultra)
        XCTAssertEqual(plan.invocation.arguments, ["--config", "/var/ultra/forward-12-aaaaaaaaaaaa.json"])
        let config = Fixtures.parseJSON(plan.configJSON)
        XCTAssertEqual(Set(config.keys), [
            "forward_id", "listen_host", "listen_port", "target", "control_socket", "policy_generation",
            "target_digest", "transport", "max_tcp_connections", "udp_queue_packets", "connect_timeout_seconds",
            "location_cache_path", "location_lock_path", "status_path", "probe_timeout_ms", "auto_select", "probe_workers",
        ])
        XCTAssertEqual(config["listen_host"] as? String, "127.0.0.1")
        XCTAssertEqual(config["listen_port"] as? Int, 32000)
        XCTAssertEqual(config["auto_select"] as? Bool, false)
        XCTAssertEqual(config["probe_workers"] as? Int, 4)
        XCTAssertEqual(config["max_tcp_connections"] as? Int, 512)
        XCTAssertEqual(config["status_path"] as? String, "/var/ultra/forward-12-aaaaaaaaaaaa.status.json")
        XCTAssertTrue(plan.configJSON.hasPrefix("{\"auto_select\":false,\"connect_timeout_seconds\":5,"), "sorted keys")

        let auto = try UltraRuntimeConfigBuilder.buildPlan(forward: Fixtures.ultraForward(), policy: policy(), paths: paths, autoSelect: true, listenPortOverride: 40001)
        let autoConfig = Fixtures.parseJSON(auto.configJSON)
        XCTAssertEqual(autoConfig["probe_target"] as? String, "1.1.1.1:53")
        XCTAssertEqual(autoConfig["auto_select"] as? Bool, true)
        // The probe window fits the Network Extension start window (the runtime binds its
        // listener only after the batch is probed); native outbound uses the same budget.
        XCTAssertEqual(autoConfig["probe_duration_seconds"] as? Int, 5)
        XCTAssertEqual(autoConfig["probe_samples"] as? Int, 20)
        XCTAssertEqual(autoConfig["probe_interval_ms"] as? Int, 250)
        XCTAssertEqual(autoConfig["listen_port"] as? Int, 40001)
    }

    func testLeaseRequestBody() throws {
        let request = try UltraLeaseRequest.ordinary(policy: policy(), deviceId: "ios-client-12345-acbf215cac438980", maxCandidates: 4, nonceHex: "00112233445566778899aabbccddeeff")
        let body = Fixtures.parseJSON(String(decoding: try request.jsonData(), as: UTF8.self))
        XCTAssertEqual(body["device_id"] as? String, "ios-client-12345-acbf215cac438980")
        let forwards = body["forwards"] as! [[String: Any]]
        XCTAssertEqual(forwards.count, 1)
        XCTAssertEqual(Set(forwards[0].keys), [
            "forward_id", "session_ticket", "policy_generation", "nonce", "target_digest", "transport", "selection_mode", "max_candidates",
        ])
        XCTAssertEqual(forwards[0]["selection_mode"] as? String, "auto")
        XCTAssertEqual(forwards[0]["max_candidates"] as? Int, 4)
        let single = try UltraLeaseRequest.ordinary(policy: policy(), deviceId: "d", maxCandidates: 1, nonceHex: "n")
        XCTAssertEqual(single.forwards[0].selectionMode, "single")
        XCTAssertThrowsError(try UltraLeaseRequest.ordinary(policy: policy(), deviceId: "d", maxCandidates: 5))
        XCTAssertEqual(UltraLeaseRequest.randomNonceHex().count, 32)
    }

    func testLeaseResponseValidation() throws {
        let response = UltraLeaseResponse(ok: true, error: nil, leases: [Fixtures.leaseJSON()])
        let leases = try response.validatedLeases(policy: policy(), maxCandidates: 4, sealer: PlaintextLeaseOpener(), now: Fixtures.now)
        XCTAssertEqual(leases.count, 1)
        var lease = leases[0]
        XCTAssertTrue(lease.isUsable(now: Fixtures.now))
        XCTAssertEqual(lease.endpointIp, "203.0.113.5")
        XCTAssertEqual(lease.directConnectivity.ipCidrs, ["203.0.113.5/32", "198.51.100.0/24"])
        XCTAssertEqual(lease.leaseHash, Hashing.sha256Hex("lease-0123456789abcdef"))
        let payload = lease.controlPayload()
        XCTAssertEqual((payload["credential"] as? [String: String])?["user"], "u")
        XCTAssertEqual(payload["expires_at"] as? String, "2027-01-15T08:05:00Z")
        lease.wipe()
        XCTAssertEqual(lease.user, [0])

        var tooFast = Fixtures.leaseJSON()
        tooFast["rate_limit_mbps"] = 500
        XCTAssertThrowsError(try UltraLeaseResponse(ok: true, error: nil, leases: [tooFast])
            .validatedLeases(policy: policy(), maxCandidates: 4, sealer: PlaintextLeaseOpener(), now: Fixtures.now))
        var sealed = Fixtures.leaseJSON()
        sealed["sealed"] = ["v": 1]
        XCTAssertThrowsError(try UltraLeaseResponse(ok: true, error: nil, leases: [sealed])
            .validatedLeases(policy: policy(), maxCandidates: 4, sealer: PlaintextLeaseOpener(), now: Fixtures.now)) { error in
            XCTAssertEqual((error as? UltraLeaseError)?.message, "lease_unseal_failed")
        }
        XCTAssertThrowsError(try UltraLeaseResponse(ok: false, error: "forward_inactive", leases: [])
            .validatedLeases(policy: policy(), maxCandidates: 4, sealer: PlaintextLeaseOpener(), now: Fixtures.now)) { error in
            XCTAssertEqual((error as? UltraLeaseError)?.message, "forward_inactive")
        }
    }

    func testControlFrameIsLengthPrefixed() throws {
        let leases = try UltraLeaseResponse(ok: true, error: nil, leases: [Fixtures.leaseJSON()])
            .validatedLeases(policy: policy(), maxCandidates: 4, sealer: PlaintextLeaseOpener(), now: Fixtures.now)
        let frame = try UltraRuntimeConfigBuilder.controlFrame(leases: leases)!
        let length = Int(frame[0]) << 24 | Int(frame[1]) << 16 | Int(frame[2]) << 8 | Int(frame[3])
        XCTAssertEqual(length, frame.count - 4)
        let reply = Data("{\"ok\":true,\"forward_id\":12,\"policy_generation\":\"\(Fixtures.generation)\"}".utf8)
        XCTAssertTrue(UltraRuntimeConfigBuilder.controlReplyAccepted(reply, forwardId: 12, policyGeneration: Fixtures.generation))
        XCTAssertFalse(UltraRuntimeConfigBuilder.controlReplyAccepted(Data("{\"ok\":1}".utf8), forwardId: 12, policyGeneration: Fixtures.generation))
    }
}
