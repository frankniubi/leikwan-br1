import XCTest
@testable import LeikwanIOSCore

enum NativeFixtures {
    static let direction = "HK -> CN"
    /// sha256("native-outbound:v1\n12\nhk -> cn\ntcp+udp\ncn_only") (Python hashlib).
    static let digestV1CnOnly = "d4c8b735031af604af376bc75ab28edc96d4f28709a22106379940bad0a26c70"
    /// sha256("native-outbound:v2\n12\nhk -> cn\ntcp+udp\nglobal\nunrestricted").
    static let digestV2GlobalUnrestricted = "e9498c78e3490ef7702ce2271343cdb2594b308f9dea03df47aca5993f7524a1"
    static let carrierDigest = String(repeating: "e", count: 64)

    static func access(transport: String = "TCP+UDP") -> [String: Any] {
        var access = Fixtures.ultraAccess()
        access["transport"] = transport
        return access
    }

    static func forwardJSON(transport: String = "TCP+UDP", direction: String = NativeFixtures.direction) -> [String: Any] {
        return [
            "forward_id": 12,
            "service_name": "Native HK",
            "forward_combination_direction": direction,
            "backend_mode": "ultra",
            "mode": "ultra",
            "transport": "tcp+udp",
            "listen_port": 32000,
            "service_status": "active",
            "target": Fixtures.target,
            "ultra_access": access(transport: transport),
        ]
    }

    static func forward(transport: String = "TCP+UDP", direction: String = NativeFixtures.direction) -> ForwardContract {
        var contract = ForwardContractParser.parse(forwardJSON(transport: transport, direction: direction))!
        contract.mode = .ultra
        return contract
    }

    static func carrierJSON(scopes: [String] = ["cn_only", "global"], unrestricted: Bool = true) -> [String: Any] {
        return [
            "forward_id": 12,
            "direction": direction,
            "scope_digest": carrierDigest.uppercased(),
            "transport": "TCP+UDP",
            "route_scopes": scopes,
            "default_route_scope": "global",
            "unrestricted_allowed": unrestricted,
        ]
    }

    static func outboundJSON(carrier: [String: Any] = carrierJSON()) -> [String: Any] {
        return [
            "enabled": true,
            "protocol": "socks5",
            "lease_protocol": "leikwan-ultra-native-socks5-v1",
            "transport": "TCP+UDP",
            "forward_ids": [12, 0, 12],
            "default_forward_id": 12,
            "route_scopes": ["global", "mainland_only", "bogus"],
            "default_route_scope": "global",
            "unrestricted_allowed": true,
            "forwards": [carrier],
        ]
    }

    static func availability() -> NativeOutboundAvailability {
        return NativeOutboundAvailability.from(outboundJSON())!
    }

    static func carrier() -> NativeOutboundCarrier {
        return availability().carrier(forwardId: 12)!
    }

    static func policy() -> UltraPolicy {
        return UltraPolicy.from(forward(), now: Fixtures.now)!
    }

    static func leaseJSON(
        scope: String = "cn_only",
        policy: String = "restricted",
        digest: String = digestV1CnOnly,
        leaseId: String = "native-lease-0123456789",
        generation: String = Fixtures.generation
    ) -> [String: Any] {
        return [
            "protocol": "leikwan-ultra-native-socks5-v1",
            "purpose": "native_outbound",
            "forward_id": 12,
            "policy_generation": generation,
            "lease_id": leaseId,
            "expires_at": Fixtures.iso(Fixtures.now.addingTimeInterval(300)),
            "transport": "tcp+udp",
            "rate_limit_mbps": 100,
            "quota_remaining_bytes": 1000,
            "selection_ref": "sel_0123456789abcdef",
            "selection_tier": "paid",
            "direction": direction,
            "route_scope": scope,
            "route_policy": policy,
            "scope_digest": digest,
            "endpoint": ["ip": "203.0.113.7", "port": 1080] as [String: Any],
            "credential": ["user": "nu", "password": "np"],
        ]
    }

    static func lease(_ raw: [String: Any] = leaseJSON(), scope: NativeRouteScope = .cnOnly, routePolicy: NativeRoutePolicy = .restricted) -> NativeOutboundLease? {
        return NativeOutboundLease.from(raw, policy: policy(), carrier: carrier(), routeScope: scope, routePolicy: routePolicy, now: Fixtures.now)
    }
}

final class NativeOutboundTests: XCTestCase {
    // MARK: Entitlement parsing

    func testAvailabilityParsing() {
        let availability = NativeFixtures.availability()
        XCTAssertTrue(availability.enabled)
        XCTAssertEqual(availability.transport, "tcp+udp")
        XCTAssertEqual(availability.forwardIds, [12])
        XCTAssertEqual(availability.defaultForwardId, 12)
        XCTAssertEqual(availability.routeScopes, [.global, .cnOnly])
        XCTAssertEqual(availability.defaultRouteScope, .global)
        XCTAssertTrue(availability.unrestrictedAllowed)
        let carrier = availability.carrier(forwardId: 12)!
        XCTAssertEqual(carrier.direction, NativeFixtures.direction)
        XCTAssertEqual(carrier.scopeDigest, NativeFixtures.carrierDigest)
        XCTAssertEqual(carrier.transport, "tcp+udp")
        XCTAssertEqual(carrier.routeScopes, [.cnOnly, .global])
        XCTAssertEqual(carrier.defaultRouteScope, .global)
        XCTAssertTrue(carrier.unrestrictedAllowed)
        XCTAssertNil(availability.carrier(forwardId: 13))
    }

    func testScopeAliasesAndPolicyNormalization() {
        XCTAssertEqual(NativeRouteScope.normalize(" Mainland_Only "), .cnOnly)
        XCTAssertEqual(NativeRouteScope.normalize("overseas_only"), .intlOnly)
        XCTAssertEqual(NativeRouteScope.normalize("GLOBAL"), .global)
        XCTAssertNil(NativeRouteScope.normalize("asia"))
        XCTAssertEqual(NativeRoutePolicy.normalize(" Unrestricted "), .unrestricted)
        XCTAssertEqual(NativeRoutePolicy.normalize("anything"), .restricted)
        XCTAssertFalse(NativeRoutePolicy.isValidValue("anything"))
        XCTAssertEqual(NativeRouteScope.cnOnly.displayName, "仅中国大陆")
        XCTAssertEqual(NativeRouteScope.intlOnly.displayName, "仅国际")
        XCTAssertEqual(NativeRouteScope.global.displayName, "全局")
        XCTAssertEqual(NativeRoutePolicy.unrestricted.displayName, "无审全路由")
    }

    func testAvailabilityRejections() {
        func parse(_ mutate: (inout [String: Any]) -> Void) -> NativeOutboundAvailability? {
            var raw = NativeFixtures.outboundJSON()
            mutate(&raw)
            return NativeOutboundAvailability.from(raw)
        }
        XCTAssertNil(NativeOutboundAvailability.from(nil))
        XCTAssertNil(parse { $0["enabled"] = false })
        XCTAssertNil(parse { $0["protocol"] = "http" })
        XCTAssertNil(parse { $0["lease_protocol"] = "leikwan-ultra-policy-v2" })
        XCTAssertNil(parse { $0["transport"] = "tcp" })
        XCTAssertNil(parse { $0["forwards"] = [] as [Any] })
        var badCarrier = NativeFixtures.carrierJSON()
        badCarrier["scope_digest"] = "short"
        XCTAssertNil(NativeOutboundAvailability.from(NativeFixtures.outboundJSON(carrier: badCarrier)), "every carrier must be usable")
        var noDefault = NativeFixtures.carrierJSON(scopes: ["cn_only"])
        noDefault["default_route_scope"] = "global"
        XCTAssertNil(NativeOutboundAvailability.from(NativeFixtures.outboundJSON(carrier: noDefault)), "default scope must be offered")
        var missingDefault = NativeFixtures.carrierJSON(scopes: ["intl_only"])
        missingDefault["default_route_scope"] = NSNull()
        XCTAssertEqual(NativeOutboundAvailability.from(NativeFixtures.outboundJSON(carrier: missingDefault))?.forwards.first?.defaultRouteScope, .intlOnly)
    }

    func testPullResponseCarriesNativeOutbound() throws {
        let body: [String: Any] = [
            "ok": true,
            "forwards": [NativeFixtures.forwardJSON()],
            "native_outbound": NativeFixtures.outboundJSON(),
        ]
        let response = PullConfigsResponse.parse(try CanonicalJSON.data(body))!
        XCTAssertNotNil(response.nativeOutbound)
        let catalog = response.catalog(selectedForwardId: nil)
        XCTAssertEqual(catalog.nativeOutbound, NativeFixtures.availability())
        XCTAssertEqual(catalog.supportedForward(id: 12)?.kind, .ultra)
        XCTAssertEqual(catalog.supportedForward(id: 12)?.contract.direction, NativeFixtures.direction)

        let plain = PullConfigsResponse.parse(try CanonicalJSON.data(["ok": true, "forwards": [] as [Any]] as [String: Any]))!
        XCTAssertNil(plain.catalog(selectedForwardId: nil).nativeOutbound)
    }

    func testDirectionsMatch() {
        XCTAssertTrue(nativeDirectionsMatch("hk  ->   cn", "HK -> CN"))
        XCTAssertFalse(nativeDirectionsMatch("", ""))
        XCTAssertFalse(nativeDirectionsMatch("HK -> CN", "SG -> CN"))
    }

    // MARK: Lease request / validation

    func testScopeDigestVectors() {
        XCTAssertEqual(
            NativeOutboundLease.scopeDigest(forwardId: 12, direction: " HK -> CN ", transport: "TCP+UDP", routeScope: "mainland_only"),
            NativeFixtures.digestV1CnOnly
        )
        XCTAssertEqual(
            NativeOutboundLease.scopeDigestV2(forwardId: 12, direction: "HK -> CN", transport: "tcp+udp", routeScope: "global", routePolicy: .unrestricted),
            NativeFixtures.digestV2GlobalUnrestricted
        )
    }

    func testNativeLeaseRequestBody() throws {
        let request = try UltraLeaseRequest.native(
            policy: NativeFixtures.policy(),
            carrier: NativeFixtures.carrier(),
            routeScope: .cnOnly,
            routePolicy: .restricted,
            deviceId: "dev",
            nonceHex: "ab"
        )
        let forward = (request.jsonObject()["forwards"] as! [[String: Any]])[0]
        XCTAssertEqual(forward["forward_id"] as? Int64, 12)
        XCTAssertEqual(forward["purpose"] as? String, "native_outbound")
        XCTAssertEqual(forward["route_scope"] as? String, "cn_only")
        XCTAssertEqual(forward["route_policy"] as? String, "restricted")
        XCTAssertEqual(forward["selection_mode"] as? String, "auto")
        XCTAssertEqual(forward["max_candidates"] as? Int, 4)
        XCTAssertEqual(forward["transport"] as? String, "tcp+udp")
        XCTAssertEqual(forward["target_digest"] as? String, NativeFixtures.digestV1CnOnly)
        XCTAssertEqual(forward["session_ticket"] as? String, "ticket-123")
        XCTAssertEqual(forward["nonce"] as? String, "ab")

        let unrestricted = try UltraLeaseRequest.native(
            policy: NativeFixtures.policy(), carrier: NativeFixtures.carrier(),
            routeScope: .global, routePolicy: .unrestricted, deviceId: "dev", maxCandidates: 1
        )
        let single = (unrestricted.jsonObject()["forwards"] as! [[String: Any]])[0]
        XCTAssertEqual(single["target_digest"] as? String, NativeFixtures.digestV2GlobalUnrestricted)
        XCTAssertEqual(single["selection_mode"] as? String, "single")
        XCTAssertThrowsError(try UltraLeaseRequest.native(
            policy: NativeFixtures.policy(), carrier: NativeFixtures.carrier(),
            routeScope: .global, routePolicy: .restricted, deviceId: "dev", maxCandidates: 5
        ))
    }

    func testNativeLeaseValidatesAndBuildsControlPayload() {
        let lease = NativeFixtures.lease()!
        XCTAssertEqual(lease.routeScope, .cnOnly)
        XCTAssertEqual(lease.routePolicy, .restricted)
        XCTAssertEqual(lease.endpointIp, "203.0.113.7")
        let payload = lease.controlPayload()
        XCTAssertEqual(Set(payload.keys), [
            "protocol", "purpose", "forward_id", "lease_id", "policy_generation", "expires_at", "transport",
            "rate_limit_mbps", "quota_remaining_bytes", "target_digest", "selection_ref", "selection_tier",
            "direction", "route_scope", "route_policy", "endpoint", "credential",
        ])
        XCTAssertEqual(payload["protocol"] as? String, "leikwan-ultra-native-socks5-v1")
        XCTAssertEqual(payload["target_digest"] as? String, NativeFixtures.digestV1CnOnly)
        XCTAssertEqual(payload["expires_at"] as? String, Fixtures.iso(Fixtures.now.addingTimeInterval(300)))
        XCTAssertEqual((payload["credential"] as? [String: String])?["user"], "nu")
        var wiped = lease
        wiped.wipe()
        XCTAssertEqual(wiped.user, [0, 0])
    }

    func testNativeLeaseRejections() {
        func reject(_ mutate: (inout [String: Any]) -> Void, scope: NativeRouteScope = .cnOnly, routePolicy: NativeRoutePolicy = .restricted, line: UInt = #line) {
            var raw = NativeFixtures.leaseJSON()
            mutate(&raw)
            XCTAssertNil(NativeFixtures.lease(raw, scope: scope, routePolicy: routePolicy), line: line)
        }
        reject { $0["purpose"] = "ultra" }
        reject { $0["protocol"] = "leikwan-ultra-policy-v2" }
        reject { $0["scope_digest"] = String(repeating: "c", count: 64) }
        reject { $0["route_scope"] = "global" }
        reject { $0["route_policy"] = "open" }
        reject { $0["route_policy"] = "unrestricted" }
        reject { $0["direction"] = "SG -> CN" }
        reject { $0["policy_generation"] = String(repeating: "b", count: 64) }
        reject { $0["expires_at"] = Fixtures.iso(Fixtures.now.addingTimeInterval(700)) }
        reject { $0["rate_limit_mbps"] = 500 }
        reject { $0["transport"] = "tcp" }
        reject({ _ in }, scope: .intlOnly)
        // Unrestricted is refused when the carrier does not allow it.
        var raw = NativeFixtures.leaseJSON(scope: "global", policy: "unrestricted", digest: NativeFixtures.digestV2GlobalUnrestricted)
        XCTAssertNotNil(NativeFixtures.lease(raw, scope: .global, routePolicy: .unrestricted))
        let strict = NativeOutboundAvailability.from(NativeFixtures.outboundJSON(carrier: NativeFixtures.carrierJSON(unrestricted: false)))!
        XCTAssertNil(NativeOutboundLease.from(raw, policy: NativeFixtures.policy(), carrier: strict.carrier(forwardId: 12)!, routeScope: .global, routePolicy: .unrestricted, now: Fixtures.now))
        // A missing selection_ref is derived from the lease id.
        raw = NativeFixtures.leaseJSON(leaseId: "native-lease-0001")
        raw["selection_ref"] = nil
        XCTAssertEqual(NativeFixtures.lease(raw)?.selectionRef, "native-a5fb3e72a0b9a22c9e781b511a10cd7bb07e0a9c4272d55911ae7aa5f451c861")
    }

    func testAuthorizationFailureCodes() {
        XCTAssertTrue(NativeOutboundErrors.isAuthorizationFailure("native_outbound_not_allowed"))
        XCTAssertTrue(NativeOutboundErrors.isAuthorizationFailure(" forward_inactive: suspended"))
        XCTAssertFalse(NativeOutboundErrors.isAuthorizationFailure("no_approved_candidate"))
        XCTAssertFalse(NativeOutboundErrors.isAuthorizationFailure("policy_generation_invalid"))
    }

    // MARK: Runtime config / control frame

    func testNativeRuntimeConfigKeysMatchAndroid() throws {
        let paths = UltraRuntimePaths.compact(directory: "/g/r", forwardId: 12)
        let plan = try UltraRuntimeConfigBuilder.buildNativePlan(
            forward: NativeFixtures.forward(), policy: NativeFixtures.policy(), paths: paths, listenPort: 50123, routePolicy: .restricted
        )
        let config = Fixtures.parseJSON(plan.configJSON)
        XCTAssertEqual(Set(config.keys), [
            "forward_id", "listen_host", "listen_port", "target", "native_socks5", "native_route_policy",
            "native_port_min", "native_filter_profile", "control_socket", "policy_generation", "target_digest",
            "transport", "max_tcp_connections", "udp_queue_packets", "connect_timeout_seconds",
            "location_cache_path", "location_lock_path", "status_path", "probe_timeout_ms", "probe_target",
            "auto_select", "probe_duration_seconds", "probe_samples", "probe_interval_ms", "probe_workers",
        ])
        XCTAssertEqual(config["listen_port"] as? Int, 50123)
        XCTAssertEqual(config["listen_host"] as? String, "127.0.0.1")
        XCTAssertEqual(config["native_socks5"] as? Bool, true)
        XCTAssertEqual(config["native_route_policy"] as? String, "restricted")
        XCTAssertEqual(config["native_port_min"] as? Int, 1000)
        XCTAssertEqual(config["native_filter_profile"] as? String, "opengfw_tcp")
        XCTAssertEqual(config["transport"] as? String, "tcp+udp")
        XCTAssertEqual(config["control_socket"] as? String, "/g/r/u12.sock")
        XCTAssertEqual(config["status_path"] as? String, "/g/r/u12.status.json")
        XCTAssertEqual(config["auto_select"] as? Bool, true)
        XCTAssertEqual(config["probe_duration_seconds"] as? Int, 5)
        XCTAssertEqual(config["probe_samples"] as? Int, 20)
        XCTAssertEqual(config["probe_interval_ms"] as? Int, 250)

        let open = try UltraRuntimeConfigBuilder.buildNativePlan(
            forward: NativeFixtures.forward(), policy: NativeFixtures.policy(), paths: paths, listenPort: 50123, routePolicy: .unrestricted
        )
        let openConfig = Fixtures.parseJSON(open.configJSON)
        XCTAssertEqual(openConfig["native_route_policy"] as? String, "unrestricted")
        XCTAssertEqual(openConfig["native_filter_profile"] as? String, "off")
    }

    func testNativeControlFrames() throws {
        let lease = NativeFixtures.lease()!
        let single = try UltraRuntimeConfigBuilder.nativeControlFrame(leases: [lease], now: Fixtures.now)!
        let length = (Int(single[0]) << 24) | (Int(single[1]) << 16) | (Int(single[2]) << 8) | Int(single[3])
        XCTAssertEqual(length, single.count - 4)
        let body = JSONLenient.parseObject(single.subdata(in: 4..<single.count))!
        XCTAssertEqual(body["purpose"] as? String, "native_outbound")

        let second = NativeFixtures.lease(NativeFixtures.leaseJSON(leaseId: "native-lease-9876543210"))!
        let batch = try UltraRuntimeConfigBuilder.nativeControlFrame(leases: [lease, second], now: Fixtures.now)!
        let batchBody = JSONLenient.parseObject(batch.subdata(in: 4..<batch.count))!
        XCTAssertEqual((batchBody["leases"] as? [Any])?.count, 2)

        XCTAssertNil(try UltraRuntimeConfigBuilder.nativeControlFrame(leases: [], now: Fixtures.now))
        XCTAssertNil(try UltraRuntimeConfigBuilder.nativeControlFrame(leases: [lease, lease, lease, lease, lease], now: Fixtures.now))
        XCTAssertNil(try UltraRuntimeConfigBuilder.nativeControlFrame(leases: [lease], now: Fixtures.now.addingTimeInterval(400)), "expired")
    }

    // MARK: Panel round trip

    func testResolveNativeLeasesThroughClient() async throws {
        let body = try CanonicalJSON.string(["ok": true, "leases": [NativeFixtures.leaseJSON()]] as [String: Any])
        let transport = MockPanelTransport([.respond(200, body)])
        let credentials = PanelCredentials(clientId: 12345, agentToken: "t", installId: "i", deviceName: "d", appVersion: "1")
        let client = PanelClient(endpoint: .cloudflare, credentials: credentials, transport: transport)
        let leases = try await client.resolveNativeOutboundLeases(
            forward: NativeFixtures.forward(),
            availability: NativeFixtures.availability(),
            routeScope: .cnOnly,
            routePolicy: .restricted,
            nonceHex: "ab",
            now: Fixtures.now
        )
        XCTAssertEqual(leases.count, 1)
        let request = transport.requests[0]
        XCTAssertEqual(request.url?.path, "/api/client/ultra/lease")
        let sent = Fixtures.parseJSON(String(decoding: request.httpBody ?? Data(), as: UTF8.self))
        let forward = (sent["forwards"] as! [[String: Any]])[0]
        XCTAssertEqual(forward["purpose"] as? String, "native_outbound")
        XCTAssertEqual(forward["route_scope"] as? String, "cn_only")
    }

    func testResolveNativeLeasesLocalRefusals() async {
        let client = PanelClient(
            endpoint: .cloudflare,
            credentials: PanelCredentials(clientId: 1, agentToken: "t", installId: "i", deviceName: "d", appVersion: "1"),
            transport: MockPanelTransport([])
        )
        func code(_ forward: ForwardContract, _ availability: NativeOutboundAvailability?, _ scope: NativeRouteScope, _ policy: NativeRoutePolicy) async -> String? {
            do {
                _ = try await client.resolveNativeOutboundLeases(forward: forward, availability: availability, routeScope: scope, routePolicy: policy, now: Fixtures.now)
                return nil
            } catch let error as UltraLeaseError {
                return error.message
            } catch {
                return "other"
            }
        }
        let refusedNil = await code(NativeFixtures.forward(), nil, .global, .restricted)
        XCTAssertEqual(refusedNil, "native_outbound_not_allowed")
        let refusedScope = await code(NativeFixtures.forward(), NativeFixtures.availability(), .intlOnly, .restricted)
        XCTAssertEqual(refusedScope, "native_route_scope_invalid")
        let refusedDirection = await code(NativeFixtures.forward(direction: "SG -> CN"), NativeFixtures.availability(), .global, .restricted)
        XCTAssertEqual(refusedDirection, "native_forward_direction_mismatch")
        let refusedTransport = await code(NativeFixtures.forward(transport: "tcp"), NativeFixtures.availability(), .global, .restricted)
        XCTAssertEqual(refusedTransport, "native_transport_invalid")
    }
}
