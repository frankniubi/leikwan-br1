import LeikwanIOSCore
import SwiftUI

extension FoldInset {
    /// Reads the active fold of an iPhone Duo in book pose from the view's geometry. Reserved
    /// regions need the iOS 27.1 SDK; older systems, other devices, and a flat or closed Duo
    /// report no active division, so nothing moves.
    init(_ proxy: GeometryProxy) {
        self.init()
        guard #available(iOS 27.1, *) else { return }
        guard let fold = proxy.reservedRegions(kind: .division).first(where: \.isActive)?.frame else { return }
        self = FoldInset(fold: fold, width: proxy.size.width)
    }
}

/// Moves the view to the wider side of an active iPhone Duo fold, so it does not cross the crease.
/// Full width when there is no active vertical fold.
private struct FoldClear: ViewModifier {
    @State private var inset = FoldInset()

    func body(content: Content) -> some View {
        content
            .padding(.leading, inset.leading)
            .padding(.trailing, inset.trailing)
            // Measured outside the padding, so moving the view does not move the fold.
            .onGeometryChange(for: FoldInset.self) { proxy in
                FoldInset(proxy)
            } action: { inset = $0 }
            .animation(.easeInOut(duration: 0.25), value: inset)
    }
}

extension View {
    /// Keeps the view clear of the iPhone Duo fold (see `FoldClear`).
    func foldClear() -> some View {
        modifier(FoldClear())
    }

    /// A single column capped at `maxWidth`, centred, and kept off the iPhone Duo fold.
    /// Replaces `.frame(maxWidth: maxWidth).frame(maxWidth: .infinity)` on full-width screens.
    func readableColumn(maxWidth: CGFloat) -> some View {
        frame(maxWidth: maxWidth)
            .frame(maxWidth: .infinity)
            .foldClear()
    }
}
