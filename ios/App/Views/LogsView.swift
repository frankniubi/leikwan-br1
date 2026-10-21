import SwiftUI
import UIKit

struct LogsView: View {
    @EnvironmentObject private var tunnel: TunnelController
    @State private var lines: [String] = []

    var body: some View {
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
                    Text(tunnel.isConnected ? "No logs yet" : "Tunnel logs appear after you connect")
                        .foregroundStyle(.secondary)
                }
            }
            .onChange(of: lines.count) { count in
                if count > 0 { proxy.scrollTo(count - 1, anchor: .bottom) }
            }
        }
        .navigationTitle("Tunnel logs")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            Button {
                UIPasteboard.general.string = lines.joined(separator: "\n")
            } label: {
                Image(systemName: "doc.on.doc")
            }
            .disabled(lines.isEmpty)
        }
        .task {
            while !Task.isCancelled {
                lines = await tunnel.fetchLogs()
                try? await Task.sleep(nanoseconds: 2_000_000_000)
            }
        }
    }
}
