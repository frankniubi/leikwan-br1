import XCTest
@testable import LeikwanIOSCore

final class BundledRuleSetsTests: XCTestCase {
    /// `ios/` of this checkout (Core/Tests/LeikwanIOSCoreTests/<this file>).
    private var iosDirectory: URL {
        return URL(fileURLWithPath: #filePath)
            .deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()
    }

    func testSourceListMatchesTheRegistryAndTheShippedFiles() throws {
        let list = try String(contentsOf: iosDirectory.appendingPathComponent("scripts/rule-sets.tsv"), encoding: .utf8)
        var listed: [String] = []
        for line in list.split(separator: "\n") where !line.hasPrefix("#") && !line.isEmpty {
            let columns = line.split(separator: "\t").map(String.init)
            XCTAssertEqual(columns.count, 2, String(line))
            listed.append(columns[0])
        }
        XCTAssertEqual(listed.count, Set(listed).count, "duplicate tags in rule-sets.tsv")
        XCTAssertEqual(Set(listed), BundledRuleSets.allTags)

        let directory = iosDirectory.appendingPathComponent("RuleSets")
        let files = try FileManager.default.contentsOfDirectory(atPath: directory.path)
        XCTAssertEqual(Set(files.filter { $0.hasSuffix(".srs") }), Set(BundledRuleSets.allTags.map(BundledRuleSets.fileName)))
        let manifest = try String(contentsOf: directory.appendingPathComponent("SHA256SUMS"), encoding: .utf8)
        XCTAssertEqual(manifest.split(separator: "\n").count, BundledRuleSets.allTags.count)
        // The shipped folder holds data only: no upstream addresses.
        XCTAssertFalse(manifest.contains("://"))
    }

    func testRegistryCoversEveryCatalog() {
        for tag in BundledRuleSets.chinaTags { XCTAssertTrue(BundledRuleSets.contains(tag), tag) }
        for preset in RoutingCategoryCatalog.seedPresets {
            for tag in preset.tags { XCTAssertTrue(BundledRuleSets.contains(tag), tag) }
        }
        XCTAssertTrue(BundledRuleSets.hasGeoip("JP"))
        XCTAssertTrue(BundledRuleSets.hasGeoip(" us "))
        XCTAssertFalse(BundledRuleSets.hasGeoip("BV"))
        XCTAssertFalse(BundledRuleSets.hasGeoip("zz"))
        XCTAssertFalse(BundledRuleSets.hasGeoip("jpn"))
        XCTAssertEqual(
            CountryCatalog.all.filter { BundledRuleSets.hasGeoip($0.code) }.count,
            CountryCatalog.all.count - BundledRuleSets.countriesWithoutGeoip.count
        )
    }

    func testLocalEntry() {
        let entry = BundledRuleSets.singboxEntry(tag: "geoip-cn", directory: "/g/rule-sets/")
        XCTAssertEqual(entry["type"] as? String, "local")
        XCTAssertEqual(entry["format"] as? String, "binary")
        XCTAssertEqual(entry["path"] as? String, "/g/rule-sets/geoip-cn.srs")
        XCTAssertEqual(BundledRuleSets.singboxEntry(tag: "geoip-cn", directory: "")["path"] as? String, "geoip-cn.srs")
    }

    func testSafePathsFollowTheProfile() {
        let registry = SafePathRegistry.iosBaseline(panelHosts: ["Panel.Example.com", "203.0.113.7", "2001:db8::1", "panel.example.com", " "])
        XCTAssertEqual(registry.entries.map { $0.id }, ["panel-api", "loopback-control"])
        XCTAssertEqual(registry.entries[0].domains, ["panel.example.com"])
        XCTAssertEqual(registry.entries[0].ipCidrs, ["203.0.113.7/32", "2001:db8:0:0:0:0:0:1/128"])
        XCTAssertTrue(SafePathRegistry.iosBaseline().entries[0].domains.isEmpty, "signed out: nothing built in")
    }
}
