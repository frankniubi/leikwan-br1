import XCTest
@testable import LeikwanIOSCore

final class LocalizationTests: XCTestCase {
    private let languages = ["en", "es", "zh-Hans", "zh-Hant", "ko"]

    private func table(_ language: String) throws -> [String: String] {
        let path = try XCTUnwrap(CoreResources.bundle.path(forResource: "Localizable", ofType: "strings", inDirectory: nil, forLocalization: language)
            ?? CoreResources.bundle.path(forResource: "Localizable", ofType: "strings", inDirectory: "\(language).lproj"),
            "no table for \(language)")
        return try XCTUnwrap(NSDictionary(contentsOfFile: path) as? [String: String], language)
    }

    func testEveryLanguageHasEveryKeyWithTheSamePlaceholders() throws {
        let base = try table("en")
        XCTAssertGreaterThan(base.count, 30)
        for language in languages where language != "en" {
            let translated = try table(language)
            XCTAssertEqual(Set(translated.keys), Set(base.keys), language)
            for (key, value) in translated {
                XCTAssertFalse(value.isEmpty, "\(language): \(key)")
                XCTAssertEqual(value.components(separatedBy: "%@").count, key.components(separatedBy: "%@").count, "\(language): \(key)")
            }
        }
    }

    func testEnglishFallbackAndFormatting() {
        XCTAssertEqual(L("Cloud sync failed (%@)", "x1"), "Cloud sync failed (x1)")
        XCTAssertEqual(L("a key that is not in the table"), "a key that is not in the table")
    }
}
