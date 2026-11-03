import CoreGraphics
import XCTest
@testable import LeikwanIOSCore

final class FoldInsetTests: XCTestCase {
    /// Unfolded iPhone Duo held landscape: 890 pt wide, an 84 pt vertical bar on the trailing
    /// edge, and a 40 pt division region (margins included) centred on the fold at x = 445.
    private let width: CGFloat = 806
    private let fold = CGRect(x: 425, y: 0, width: 40, height: 626)

    func testColumnMovesBeforeAFoldWithMoreRoomBeforeIt() {
        let inset = FoldInset(fold: fold, width: width)
        XCTAssertEqual(inset.leading, 0)
        XCTAssertEqual(inset.trailing, width - 425)
    }

    func testColumnMovesAfterAFoldWithMoreRoomAfterIt() {
        // Vertical bar on the leading edge: the fold sits nearer the leading side.
        let inset = FoldInset(fold: CGRect(x: 341, y: 0, width: 40, height: 626), width: width)
        XCTAssertEqual(inset.leading, 381)
        XCTAssertEqual(inset.trailing, 0)
    }

    func testHorizontalFoldLeavesTheColumnCentred() {
        // Inner display held portrait in tabletop pose: the fold runs across the screen.
        let inset = FoldInset(fold: CGRect(x: 0, y: 425, width: 626, height: 40), width: 626)
        XCTAssertEqual(inset, FoldInset())
    }

    func testFoldAtTheEdgeLeavesTheColumnCentred() {
        // One half of Split View: the fold sits on the window's edge, not inside it.
        XCTAssertEqual(FoldInset(fold: CGRect(x: 425, y: 0, width: 40, height: 626), width: 445), FoldInset())
        XCTAssertEqual(FoldInset(fold: CGRect(x: -20, y: 0, width: 40, height: 626), width: 445), FoldInset())
    }

    func testNarrowSidesLeaveTheColumnCentred() {
        let inset = FoldInset(fold: CGRect(x: 250, y: 0, width: 40, height: 626), width: 520)
        XCTAssertEqual(inset, FoldInset())
    }
}
