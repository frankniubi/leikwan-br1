import LeikwanIOSCore
import SwiftUI

extension FoldInset {
    /// Reads the active fold of an iPhone Duo in book pose from the view's geometry. Reserved
    /// regions need the iOS 27.1 SDK; older systems, other devices, and a flat or closed Duo
    /// report no active division, so the column stays centred.
    init(_ proxy: GeometryProxy) {
        self.init()
        guard #available(iOS 27.1, *) else { return }
        guard let fold = proxy.reservedRegions(kind: .division).first(where: \.isActive)?.frame else { return }
        self = FoldInset(fold: fold, width: proxy.size.width)
    }
}

/// A single column capped at `maxWidth`, centred, and kept off the iPhone Duo fold.
private struct ReadableColumn: ViewModifier {
    let maxWidth: CGFloat
    @State private var inset = FoldInset()

    func body(content: Content) -> some View {
        content
            .frame(maxWidth: maxWidth)
            .frame(maxWidth: .infinity)
            .padding(.leading, inset.leading)
            .padding(.trailing, inset.trailing)
            // Measured outside the padding, so moving the column does not move the fold.
            .onGeometryChange(for: FoldInset.self) { proxy in
                FoldInset(proxy)
            } action: { inset = $0 }
            .animation(.easeInOut(duration: 0.25), value: inset)
    }
}

extension View {
    /// Replaces `.frame(maxWidth: maxWidth).frame(maxWidth: .infinity)` on full-width screens.
    func readableColumn(maxWidth: CGFloat) -> some View {
        modifier(ReadableColumn(maxWidth: maxWidth))
    }
}
