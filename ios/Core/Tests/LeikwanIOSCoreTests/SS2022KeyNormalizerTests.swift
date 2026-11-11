import XCTest
@testable import LeikwanIOSCore

final class SS2022KeyNormalizerTests: XCTestCase {
    func testValidBase64UnpaddedAccepted() {
        let key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=" // 44 chars padded, 32 bytes
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=")
    }

    func testValidBase64PaddedNormalized() {
        let key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=" // 44 chars with padding
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=") // stays padded
    }

    func testColonSeparatedPair() {
        let key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=:MTIzNDU2Nzg5MGFiY2RlZmdoaWprbG1ub3BxcnN0dXY="
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=:MTIzNDU2Nzg5MGFiY2RlZmdoaWprbG1ub3BxcnN0dXY=")
    }

    func testColonSeparatedPairWithPadding() {
        let key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=:MTIzNDU2Nzg5MGFiY2RlZmdoaWprbG1ub3BxcnN0dXY="
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=:MTIzNDU2Nzg5MGFiY2RlZmdoaWprbG1ub3BxcnN0dXY=")
    }

    func testRawASCII32CharKeyAcceptedAndReencoded() {
        let rawKey = "a!b@c#d$e%f^g&h*i(j)k-l=m[n]o{p}" // 32 ASCII chars, not valid base64
        let result = SS2022KeyNormalizer.normalizePassword(rawKey, method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YSFiQGMjZCRlJWZeZyZoKmkoailrLWw9bVtuXW97cH0=")
    }

    func testOverLongKeyHashedToLength() {
        // 59 decoded bytes (base64 of "abcdefghijklmnopqrstuvwxyz012345678901234567890123456789012")
        let key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        // SHA256 of the decoded bytes, truncated to 32, re-encoded unpadded
        XCTAssertEqual(result.normalized, "NqNX0VGXr0Tvoc7+bFbeqUol4l1isa6GVPwNfzS/NfM=")
    }

    func testUnderLengthKeyRejected() {
        let key = "YWJjZGVmZ2hpamtsbQ" // 13 decoded bytes
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-256-gcm")
        XCTAssertNotNil(result.error)
        XCTAssertTrue(result.error!.contains("13 字节"), "actual: \(result.error ?? "")")
        XCTAssertTrue(result.error!.contains("32 字节"), "actual: \(result.error ?? "")")
    }

    func testEmptyPSKRejected() {
        let result = SS2022KeyNormalizer.normalizePassword("", method: "2022-blake3-aes-256-gcm")
        XCTAssertNotNil(result.error)
        XCTAssertTrue(result.error!.contains("PSK 为空"))
    }

    func testNonSS2022MethodPassesThrough() {
        let password = "some-legacy-password"
        let result = SS2022KeyNormalizer.normalizePassword(password, method: "aes-256-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, password)
    }

    func testRawASCIIKeyOfWrongLengthIsRejected() {
        let rawKey = "a!b@c#d$e%f^g&h*i(j)k-l=m[n]o{p}xyz" // 35 ASCII chars, not valid base64
        let result = SS2022KeyNormalizer.normalizePassword(rawKey, method: "2022-blake3-aes-256-gcm")
        XCTAssertNotNil(result.error)
        XCTAssertTrue(result.error!.contains("可打印 ASCII"))
    }

    func testRawASCIIKeyWithNonASCIICharIsRejected() {
        let rawKey = "a!b@c#d$e%f^g&h*i(j)k-l=m[n]o{p}密" // 32 chars but not ASCII
        let result = SS2022KeyNormalizer.normalizePassword(rawKey, method: "2022-blake3-aes-256-gcm")
        XCTAssertNotNil(result.error)
        XCTAssertTrue(result.error!.contains("可打印 ASCII"))
    }

    func testChacha20MethodSupported() {
        let key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY="
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-chacha20-poly1305")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=")
    }

    func testAES128RequiresOnly16Bytes() {
        let key = "YWJjZGVmZ2hpamtsbW5vcA=" // 16 bytes (padded)
        let result = SS2022KeyNormalizer.normalizePassword(key, method: "2022-blake3-aes-128-gcm")
        XCTAssertNil(result.error)
        XCTAssertEqual(result.normalized, "YWJjZGVmZ2hpamtsbW5vcA=")
    }

    func testMultiUserWithOneOverLongSegmentNormalizesThatSegmentOnly() {
        let exactKey = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="
        let longKey = "VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM="
        let result = SS2022KeyNormalizer.normalizePassword("\(exactKey):\(longKey)", method: "2022-blake3-aes-256-gcm")
        XCTAssertNil(result.error)
        let parts = result.normalized.split(separator: ":").map(String.init)
        XCTAssertEqual(parts.count, 2)
        XCTAssertEqual(parts[0], exactKey)
        XCTAssertEqual(parts[1].count, 44) // hashed segment, padded
    }
}
