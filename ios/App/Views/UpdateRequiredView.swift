import SwiftUI

/// Full-screen gate shown instead of the app when the panel requires a newer build
/// (`AppModel.updateRequired`). Neutral wording with no link or store name (App Review);
/// the running tunnel is left alone. "Check again" re-pulls the catalog, which re-evaluates the
/// policy if the server changed it.
struct UpdateRequiredView: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        VStack(spacing: 20) {
            Spacer()

            Image(systemName: "arrow.down.app")
                .font(.system(size: 56, weight: .regular))
                .foregroundStyle(Palette.primary)
                .accessibilityHidden(true)

            Text(L("Update required"))
                .font(.title.weight(.bold))
                .foregroundStyle(Palette.onSurface)
                .multilineTextAlignment(.center)

            Text(L("A newer version of Bridge is required. Install the latest version and open the app again."))
                .font(.body)
                .foregroundStyle(Palette.onSurfaceVariant)
                .multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)

            Spacer()

            Button {
                Task { await model.refreshCatalog() }
            } label: {
                HStack {
                    if model.isRefreshing {
                        ProgressView().tint(Palette.onPrimary)
                    } else {
                        Text(L("Check again")).font(.headline)
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 52)
                .foregroundStyle(Palette.onPrimary)
                .background(Palette.primary, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            }
            .buttonStyle(.plain)
            .disabled(model.isRefreshing)
        }
        .padding(.horizontal, Metrics.pagePadding * 2)
        .padding(.vertical, Metrics.pagePadding)
        .frame(maxWidth: 520)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Palette.background.ignoresSafeArea())
    }
}
