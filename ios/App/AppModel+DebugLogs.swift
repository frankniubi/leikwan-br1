import Foundation
import LeikwanIOSCore

extension AppModel {
    private static let lastUploadedDebugCollectionKey = "last_uploaded_debug_collection_uuid"

    func uploadDebugLogsNow(liveLines: [String] = []) async throws -> (referenceCode: String, bytes: Int) {
        guard let client else {
            throw AppError(L("Sign in before uploading logs"))
        }
        let started = try await client.startDebugCollection()
        let bundle = await collectDebugLogBundle(liveLines: liveLines)
        let uploaded = try await client.uploadDebugLogBundle(uuid: started.collection.uuid, rawText: bundle)
        UserDefaults.standard.set(started.collection.uuid, forKey: Self.lastUploadedDebugCollectionKey)
        return (started.referenceCode, uploaded.bytes)
    }

    func scheduleDebugLogUpload(after response: PullConfigsResponse) {
        guard let collection = response.debugCollection, collection.isActive, let client else { return }
        let lastUUID = UserDefaults.standard.string(forKey: Self.lastUploadedDebugCollectionKey) ?? ""
        if lastUUID.caseInsensitiveCompare(collection.uuid) == .orderedSame { return }
        Task {
            do {
                let bundle = await collectDebugLogBundle(liveLines: [])
                _ = try await client.uploadDebugLogBundle(uuid: collection.uuid, rawText: bundle)
                UserDefaults.standard.set(collection.uuid, forKey: Self.lastUploadedDebugCollectionKey)
            } catch {
                // Best-effort passive upload; retried on the next pull while active.
            }
        }
    }

    private func collectDebugLogBundle(liveLines: [String]) async -> String {
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
        if !recent.isEmpty {
            sections.append("=== live tunnel ring ===")
            sections.append(recent.joined(separator: "\n"))
        }

        if let persisted = try? Data(contentsOf: AppGroup.tunnelLogURL), !persisted.isEmpty {
            sections.append("=== tunnel.log (app group) ===")
            sections.append(String(decoding: persisted.suffix(512 * 1024), as: UTF8.self))
        }

        let stderrURL = AppGroup.tempDirectory.appendingPathComponent("stderr.log")
        if let stderrData = try? Data(contentsOf: stderrURL), !stderrData.isEmpty {
            sections.append("=== stderr.log (libbox) ===")
            sections.append(String(decoding: stderrData.suffix(256 * 1024), as: UTF8.self))
        }

        return sections.joined(separator: "\n") + "\n"
    }
}
