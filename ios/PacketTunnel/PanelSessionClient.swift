import Foundation
import LeikwanIOSCore

extension TunnelPanelSession {
    /// A panel client for this session (server address, then the backup), or nil when the stored
    /// address does not parse (a launch snapshot written by an older build).
    func makeClient() -> PanelClient? {
        guard let server = PanelEndpoint(storage: endpoint) else { return nil }
        let credentials = PanelCredentials(
            clientId: clientID,
            agentToken: agentToken,
            installId: installID,
            deviceName: deviceName,
            appVersion: appVersion,
            appBuild: appBuild ?? ""
        )
        return PanelClient(endpoint: server, fallback: PanelEndpoint(storage: backupEndpoint), credentials: credentials)
    }
}
