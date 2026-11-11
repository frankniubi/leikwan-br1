import SwiftUI
import UIKit

struct LogsView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController
    @State private var lines: [String] = []
    @State private var uploading = false
    @State private var uploadBanner: String?

    var body: some View {
        VStack(spacing: 0) {
            if let uploadBanner {
                Text(uploadBanner)
                    .font(.caption.monospaced())
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 8)
                    .background(Color.secondary.opacity(0.12))
                    .textSelection(.enabled)
            }
            ScrollViewReader { proxy in
                List(Array(lines.enumerated()), id: \.offset) { item in
                    Text(item.element)
                        .font(.caption2.monospaced())
                        .textSelection(.enabled)
                        .id(item.offset)
                }
                .listStyle(.plain)
                .overlay {
                    if lines.isEmpty {
                        Text(tunnel.isConnected ? L("No logs yet") : L("Tunnel logs appear after you connect"))
                            .foregroundStyle(.secondary)
                    }
                }
                .onChange(of: lines.count) { count in
                    if count > 0 { proxy.scrollTo(count - 1, anchor: .bottom) }
                }
            }
        }
        .navigationTitle(L("Tunnel logs"))
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            HStack(spacing: 16) {
                Button {
                    Task { await upload() }
                } label: {
                    if uploading {
                        ProgressView()
                    } else {
                        Image(systemName: "icloud.and.arrow.up")
                    }
                }
                .disabled(uploading)
                .accessibilityLabel(L("Upload diagnostic logs"))

                Button {
                    UIPasteboard.general.string = lines.joined(separator: "\n")
                } label: {
                    Image(systemName: "doc.on.doc")
                }
                .disabled(lines.isEmpty)
            }
        }
        .task {
            while !Task.isCancelled {
                lines = await tunnel.fetchLogs()
                try? await Task.sleep(nanoseconds: 2_000_000_000)
            }
        }
    }

    private func upload() async {
        uploading = true
        defer { uploading = false }
        do {
            let result = try await model.uploadDebugLogsNow(liveLines: lines)
            UIPasteboard.general.string = result.referenceCode
            uploadBanner = L("Uploaded diagnostic logs (ref %@, copied)", result.referenceCode)
        } catch {
            uploadBanner = error.localizedDescription
        }
    }
}
