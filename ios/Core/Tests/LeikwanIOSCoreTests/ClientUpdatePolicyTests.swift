import XCTest
@testable import LeikwanIOSCore

final class ClientUpdatePolicyTests: XCTestCase {
    private func pull(_ json: String) throws -> PullConfigsResponse {
        return try XCTUnwrap(PullConfigsResponse.parse(Data(json.utf8)), json)
    }

    private func policy(_ json: String) throws -> ClientUpdatePolicy? {
        return try pull(json).clientUpdatePolicy
    }

    func testOlderBuildIsRequiredToUpdate() throws {
        let parsed = try XCTUnwrap(policy(#"{"ok":true,"forwards":[],"client_update":{"minimum_build":52}}"#))
        XCTAssertEqual(parsed.minimumBuild, 52)
        XCTAssertTrue(parsed.requiresUpdate(currentBuild: "51"))
        XCTAssertTrue(parsed.requiresUpdate(currentBuild: " 7 "))
    }

    func testEqualOrNewerBuildIsNotRequired() throws {
        let parsed = try XCTUnwrap(policy(#"{"ok":true,"client_update":{"minimum_build":52}}"#))
        XCTAssertFalse(parsed.requiresUpdate(currentBuild: "52"))
        XCTAssertFalse(parsed.requiresUpdate(currentBuild: "53"))
        XCTAssertFalse(parsed.requiresUpdate(currentBuild: "1000"))
    }

    func testAbsentKeyIsNoPolicy() throws {
        XCTAssertNil(try policy(#"{"ok":true,"forwards":[]}"#))
    }

    func testGarbageMinimumFailsOpen() throws {
        let cases = [
            #"{"client_update":{}}"#,
            #"{"client_update":{"minimum_build":null}}"#,
            #"{"client_update":{"minimum_build":"52"}}"#,
            #"{"client_update":{"minimum_build":"abc"}}"#,
            #"{"client_update":{"minimum_build":true}}"#,
            #"{"client_update":{"minimum_build":52.5}}"#,
            #"{"client_update":{"minimum_build":1e300}}"#,
            #"{"client_update":{"minimum_build":[52]}}"#,
            #"{"client_update":null}"#,
            #"{"client_update":"52"}"#,
            #"{"client_update":52}"#,
        ]
        for json in cases {
            // The key is present, so a policy comes back, but it never requires an update.
            let parsed = try XCTUnwrap(policy(json), json)
            XCTAssertNil(parsed.minimumBuild, json)
            XCTAssertFalse(parsed.requiresUpdate(currentBuild: "1"), json)
        }
        // An integral float is still an integer.
        XCTAssertEqual(try policy(#"{"client_update":{"minimum_build":52.0}}"#)?.minimumBuild, 52)
    }

    func testNonNumericBuildFailsOpen() {
        let parsed = ClientUpdatePolicy(minimumBuild: 52)
        for build in [nil, "", "  ", "1.0", "51.9", "abc", "51a", "-1", "+5", "٣", "99999999999999999999999"] as [String?] {
            XCTAssertFalse(parsed.requiresUpdate(currentBuild: build), String(describing: build))
        }
        XCTAssertEqual(ClientUpdatePolicy.buildNumber("0052"), 52)
        XCTAssertNil(ClientUpdatePolicy.buildNumber("1.0"))
    }

    func testMergedKeepsThePreviousPolicyUntilAResponseCarriesOne() throws {
        let seen = ClientUpdatePolicy(minimumBuild: 52)
        // A later pull without client_update keeps what was seen.
        XCTAssertEqual(ClientUpdatePolicy.merged(previous: seen, raw: try pull(#"{"ok":true}"#).raw), seen)
        XCTAssertNil(ClientUpdatePolicy.merged(previous: nil, raw: try pull(#"{"ok":true}"#).raw))
        // A response with client_update replaces it, including lowering or clearing the minimum.
        XCTAssertEqual(ClientUpdatePolicy.merged(previous: seen, raw: try pull(#"{"client_update":{"minimum_build":40}}"#).raw),
                       ClientUpdatePolicy(minimumBuild: 40))
        XCTAssertEqual(ClientUpdatePolicy.merged(previous: seen, raw: try pull(#"{"client_update":{}}"#).raw),
                       ClientUpdatePolicy(minimumBuild: nil))
    }
}
