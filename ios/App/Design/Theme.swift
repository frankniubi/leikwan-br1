import SwiftUI
import UIKit

/// Material 3 style tonal palette (FlClash look) with light/dark variants.
enum Palette {
    static let primary = dynamic(light: 0x4F5BD5, dark: 0xBAC3FF)
    static let onPrimary = dynamic(light: 0xFFFFFF, dark: 0x1A2678)
    static let primaryContainer = dynamic(light: 0xDEE0FF, dark: 0x343F8F)
    static let onPrimaryContainer = dynamic(light: 0x0B1660, dark: 0xDEE0FF)
    static let secondaryContainer = dynamic(light: 0xE1E1F5, dark: 0x45475A)
    static let onSecondaryContainer = dynamic(light: 0x191B2C, dark: 0xE1E1F5)

    static let background = dynamic(light: 0xFBF8FF, dark: 0x121318)
    static let surfaceContainerLow = dynamic(light: 0xF5F2FA, dark: 0x1A1B21)
    static let surfaceContainer = dynamic(light: 0xEFEDF4, dark: 0x1F1F25)
    static let surfaceContainerHigh = dynamic(light: 0xE9E7EF, dark: 0x292A2F)
    static let surfaceContainerHighest = dynamic(light: 0xE3E1E9, dark: 0x34343A)
    static let onSurface = dynamic(light: 0x1B1B21, dark: 0xE4E1E9)
    static let onSurfaceVariant = dynamic(light: 0x46464F, dark: 0xC7C5D0)
    static let outlineVariant = dynamic(light: 0xC7C5D0, dark: 0x46464F)

    static let warningContainer = dynamic(light: 0xFFDEA8, dark: 0x5C4200)
    static let onWarningContainer = dynamic(light: 0x271900, dark: 0xFFDEA8)
    static let successContainer = dynamic(light: 0xB7F1B9, dark: 0x0F5223)
    static let onSuccessContainer = dynamic(light: 0x002107, dark: 0xB7F1B9)
    static let errorContainer = dynamic(light: 0xFFDAD6, dark: 0x93000A)
    static let onErrorContainer = dynamic(light: 0x410002, dark: 0xFFDAD6)

    static let upload = dynamic(light: 0x4F5BD5, dark: 0xBAC3FF)
    static let download = dynamic(light: 0x13A378, dark: 0x6FDBB3)
    static let good = dynamic(light: 0x1E8E3E, dark: 0x81C995)
    static let fair = dynamic(light: 0xC27C0E, dark: 0xFDD663)
    static let bad = dynamic(light: 0xC5221F, dark: 0xF28B82)

    private static func dynamic(light: UInt32, dark: UInt32) -> Color {
        Color(uiColor: UIColor { traits in
            UIColor(rgb: traits.userInterfaceStyle == .dark ? dark : light)
        })
    }
}

private extension UIColor {
    convenience init(rgb: UInt32) {
        self.init(
            red: CGFloat((rgb >> 16) & 0xFF) / 255,
            green: CGFloat((rgb >> 8) & 0xFF) / 255,
            blue: CGFloat(rgb & 0xFF) / 255,
            alpha: 1
        )
    }
}

enum Metrics {
    static let cardRadius: CGFloat = 16
    static let gutter: CGFloat = 12
    static let pagePadding: CGFloat = 16
}

/// Rounded tonal surface used for every dashboard and list card.
struct CardBackground: ViewModifier {
    var fill: Color = Palette.surfaceContainer
    var selected = false

    func body(content: Content) -> some View {
        content
            .background(
                RoundedRectangle(cornerRadius: Metrics.cardRadius, style: .continuous)
                    .fill(selected ? Palette.primaryContainer : fill)
            )
            .overlay(
                RoundedRectangle(cornerRadius: Metrics.cardRadius, style: .continuous)
                    .strokeBorder(selected ? Palette.primary.opacity(0.55) : Color.clear, lineWidth: 1.5)
            )
    }
}

extension View {
    func card(fill: Color = Palette.surfaceContainer, selected: Bool = false) -> some View {
        modifier(CardBackground(fill: fill, selected: selected))
    }

    /// Page background shared by all screens.
    func pageBackground() -> some View {
        background(Palette.background.ignoresSafeArea())
    }
}

/// Small icon + label header at the top of a dashboard card.
struct CardHeader: View {
    let title: String
    let systemImage: String
    var trailing: AnyView?

    init(_ title: String, systemImage: String, trailing: AnyView? = nil) {
        self.title = title
        self.systemImage = systemImage
        self.trailing = trailing
    }

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: systemImage)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(Palette.onSurfaceVariant)
                .frame(width: 20)
            Text(title)
                .font(.subheadline.weight(.medium))
                .foregroundStyle(Palette.onSurfaceVariant)
                .lineLimit(1)
            Spacer(minLength: 4)
            if let trailing {
                trailing
            }
        }
    }
}

/// Section title above a group of cards.
struct SectionTitle: View {
    let text: String
    var trailing: AnyView?

    init(_ text: String, trailing: AnyView? = nil) {
        self.text = text
        self.trailing = trailing
    }

    var body: some View {
        HStack {
            Text(text)
                .font(.title3.weight(.semibold))
                .foregroundStyle(Palette.onSurface)
            Spacer()
            if let trailing {
                trailing
            }
        }
        .padding(.top, 8)
    }
}

enum LatencyStyle {
    static func color(_ milliseconds: Int?) -> Color {
        guard let milliseconds else { return Palette.onSurfaceVariant }
        if milliseconds < 200 { return Palette.good }
        if milliseconds < 500 { return Palette.fair }
        return Palette.bad
    }

    static func text(_ milliseconds: Int?, failed: Bool) -> String {
        if let milliseconds { return "\(milliseconds) ms" }
        return failed ? "超时" : "—"
    }
}

/// Tonal pill used for small status labels.
struct Pill: View {
    let text: String
    var fill: Color = Palette.secondaryContainer
    var foreground: Color = Palette.onSecondaryContainer

    var body: some View {
        Text(text)
            .font(.caption2.weight(.semibold))
            .foregroundStyle(foreground)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(fill, in: Capsule())
    }
}
