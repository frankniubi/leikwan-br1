import Foundation
import LeikwanIOSCore

extension AppModel {
    private static let lastUploadedDebugCollectionKey = "last_uploaded_debug_collection_uuid"

    func uploadDebugLogsNow(liveLines: [String] = []) async throws -> (referenceCode: String, bytes: Int) {
        guard let client = try await activePanelClient(), let profile else {
            throw AppError(L("Sign in before uploading logs"))
        }
        let scope = profile.accountKey + "#" + client.credentials.deviceId
        let result = try await debugLogUploads.run(
            scope: scope,
            start: { try await client.startDebugCollection() },
            collect: { try await self.collectDebugLogBundle(liveLines: liveLines, scope: scope) },
            send: { bundle in
                try self.checkDebugUploadAccount(scope)
                _ = try await client.uploadDebugLogBundle(bundle, validateContext: {
                    try self.checkDebugUploadAccount(scope)
                })
                try self.checkDebugUploadAccount(scope)
            },
            completed: Self.markDebugCollectionUploaded
        )
        guard let result else { throw DebugLogUploadError.busy }
        return (result.referenceCode, result.bytes)
    }

    func scheduleDebugLogUpload(after response: PullConfigsResponse) {
        guard let collection = response.debugCollection, let client, let profile,
              passiveDebugLogUpload == nil else { return }
        let lastUUID = UserDefaults.standard.string(forKey: Self.lastUploadedDebugCollectionKey) ?? ""
        let scope = profile.accountKey + "#" + client.credentials.deviceId
        // Fire-and-forget: pulling configs / acquiring leases never waits for diagnostics.
        passiveDebugLogUpload = Task(priority: .utility) {
            defer { passiveDebugLogUpload = nil }
            do {
                _ = try await debugLogUploads.run(
                    scope: scope, collection: collection, lastCompletedCollectionUUID: lastUUID,
                    start: { try await client.startDebugCollection() },
                    collect: { try await self.collectDebugLogBundle(liveLines: [], scope: scope) },
                    send: { bundle in
                        try self.checkDebugUploadAccount(scope)
                        _ = try await client.uploadDebugLogBundle(bundle, validateContext: {
                            try self.checkDebugUploadAccount(scope)
                        })
                        try self.checkDebugUploadAccount(scope)
                    },
                    completed: Self.markDebugCollectionUploaded
                )
            } catch {
                // Durable policy decides whether a later authenticated pull can retry.
                // Cancellation never marks the collection complete.
            }
        }
    }

    private static func markDebugCollectionUploaded(_ bundle: PendingDebugLogBundle) {
        UserDefaults.standard.set(bundle.collection.uuid, forKey: lastUploadedDebugCollectionKey)
    }

    private func checkDebugUploadAccount(_ scope: String) throws {
        try Task.checkCancellation()
        guard let profile, let client,
              scope == profile.accountKey + "#" + client.credentials.deviceId else {
            throw CancellationError()
        }
    }

    private func collectDebugLogBundle(liveLines: [String], scope: String) async throws -> String {
        try checkDebugUploadAccount(scope)
        let appVersion = (Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String) ?? "?"
        let buildNumber = (Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String) ?? "?"
        let iso = ISO8601DateFormatter().string(from: Date())
        var sections: [String] = [
            "=== leikwan-ios version=\(appVersion) (\(buildNumber)) connected=\(tunnel.isConnected) utc=\(iso) ===",
        ]
        if let lastErr = tunnel.lastError, !lastErr.isEmpty {
            sections.append("last_tunnel_error: \(lastErr)")
        }
        if let catErr = catalogError, !catErr.isEmpty {
            sections.append("last_catalog_error: \(catErr)")
        }

        let recent = liveLines.isEmpty ? await tunnel.fetchLogs() : liveLines
        try checkDebugUploadAccount(scope)
        if !recent.isEmpty {
            sections.append("=== live tunnel ring ===")
            // Bound accumulation before joining a potentially large caller-supplied array.
            var parts: [Data] = []
            var remaining = 512 * 1024
            for line in recent.reversed() {
                guard remaining > 0 else { break }
                let part = Data(Data((line + "\n").utf8).suffix(remaining))
                parts.append(part)
                remaining -= part.count
            }
            var tail = Data()
            tail.reserveCapacity(512 * 1024 - remaining)
            for part in parts.reversed() { tail.append(part) }
            sections.append(String(decoding: tail, as: UTF8.self))
        }

        let logURL = AppGroup.tunnelLogURL
        let stderrURL = AppGroup.tempDirectory.appendingPathComponent("stderr.log")
        let files = await Task.detached(priority: .utility) {
            [Self.readDebugLogTail(logURL, limit: 512 * 1024),
             Self.readDebugLogTail(stderrURL, limit: 256 * 1024)]
        }.value
        try checkDebugUploadAccount(scope)
        if !files[0].isEmpty {
            sections.append("=== tunnel.log (app group) ===")
            sections.append(files[0])
        }
        if !files[1].isEmpty {
            sections.append("=== stderr.log (libbox) ===")
            sections.append(files[1])
        }
        var raw = sections.joined(separator: "\n") + "\n"
        // Also remove the actual credential if a log printed it without a recognized label.
        // It is used only in memory; neither credentials nor auth headers enter the record.
        if let token = client?.credentials.agentToken.trimmingCharacters(in: .whitespacesAndNewlines), !token.isEmpty {
            raw = raw.replacingOccurrences(of: token, with: "[REDACTED]")
        }
        return raw
    }

    nonisolated private static func readDebugLogTail(_ url: URL, limit: Int) -> String {
        guard let file = try? FileHandle(forReadingFrom: url) else { return "" }
        defer { try? file.close() }
        do {
            let end = try file.seekToEnd()
            try file.seek(toOffset: end > UInt64(limit) ? end - UInt64(limit) : 0)
            return String(decoding: try file.read(upToCount: limit) ?? Data(), as: UTF8.self)
        } catch {
            return ""
        }
    }
}
