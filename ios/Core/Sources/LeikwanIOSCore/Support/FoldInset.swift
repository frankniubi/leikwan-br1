import CoreGraphics

/// Room taken from one side of a centred column so it stays clear of the iPhone Duo fold.
///
/// While the Duo is partially folded (book pose), the fold is an active division region. A
/// centred column would bend across it, so the column moves to the wider side of a vertical
/// fold. A horizontal fold (tabletop pose) is ignored: scrolling content crosses it anyway.
/// The app reads the fold from SwiftUI (`App/Design/FoldLayout.swift`); this is the arithmetic.
public struct FoldInset: Equatable, Sendable {
    public var leading: CGFloat = 0
    public var trailing: CGFloat = 0

    /// Narrower than this, a side is not worth moving to; the column stays centred.
    public static let minimumSide: CGFloat = 280

    public init() {}

    /// `fold` is the division frame (its margins included) in the column's coordinate space,
    /// `width` the column's full width.
    public init(fold: CGRect, width: CGFloat) {
        guard fold.height >= fold.width, fold.minX > 0, fold.maxX < width else { return }
        let before = fold.minX
        let after = width - fold.maxX
        guard max(before, after) >= Self.minimumSide else { return }
        if before >= after {
            trailing = width - fold.minX
        } else {
            leading = fold.maxX
        }
    }
}
