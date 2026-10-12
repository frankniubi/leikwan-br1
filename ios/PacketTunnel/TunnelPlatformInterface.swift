import Foundation
import Libbox
import Network
import NetworkExtension
import UserNotifications

/// libbox platform interface for the iOS packet tunnel (sing-box 1.13.x).
/// Modeled on the platform interface of the official sing-box Apple client
/// for the same libbox version; macOS/tvOS paths are dropped.
final class TunnelPlatformInterface: NSObject, LibboxPlatformInterfaceProtocol, LibboxCommandServerHandlerProtocol {
    private unowned let tunnel: PacketTunnelProvider
    private var networkSettings: NEPacketTunnelNetworkSettings?
    private var pathMonitor: NWPathMonitor?

    init(_ tunnel: PacketTunnelProvider) {
        self.tunnel = tunnel
    }

    // MARK: TUN

    public func openTun(_ options: LibboxTunOptionsProtocol?, ret0_: UnsafeMutablePointer<Int32>?) throws {
        try runBlocking { [self] in
            try await openTun0(options, ret0_)
        }
    }

    private func openTun0(_ options: LibboxTunOptionsProtocol?, _ ret0_: UnsafeMutablePointer<Int32>?) async throws {
        guard let options else {
            throw RuntimeHostError("nil tun options")
        }
        guard let ret0_ else {
            throw RuntimeHostError("nil return pointer")
        }

        let settings = NEPacketTunnelNetworkSettings(tunnelRemoteAddress: "127.0.0.1")
        if options.getAutoRoute() {
            settings.mtu = NSNumber(value: options.getMTU())

            let dnsServer = try options.getDNSServerAddress()
            let dnsSettings = NEDNSSettings(servers: [dnsServer.value])
            settings.dnsSettings = dnsSettings

            var ipv4Address: [String] = []
            var ipv4Mask: [String] = []
            let ipv4AddressIterator = options.getInet4Address()!
            while ipv4AddressIterator.hasNext() {
                let prefix = ipv4AddressIterator.next()!
                ipv4Address.append(prefix.address())
                ipv4Mask.append(prefix.mask())
            }
            let ipv4Settings = NEIPv4Settings(addresses: ipv4Address, subnetMasks: ipv4Mask)
            var ipv4Routes: [NEIPv4Route] = []
            var ipv4ExcludeRoutes: [NEIPv4Route] = []
            let inet4RouteAddressIterator = options.getInet4RouteAddress()!
            if inet4RouteAddressIterator.hasNext() {
                while inet4RouteAddressIterator.hasNext() {
                    let prefix = inet4RouteAddressIterator.next()!
                    ipv4Routes.append(NEIPv4Route(destinationAddress: prefix.address(), subnetMask: prefix.mask()))
                }
            } else {
                ipv4Routes.append(NEIPv4Route.default())
            }
            let inet4RouteExcludeAddressIterator = options.getInet4RouteExcludeAddress()!
            while inet4RouteExcludeAddressIterator.hasNext() {
                let prefix = inet4RouteExcludeAddressIterator.next()!
                ipv4ExcludeRoutes.append(NEIPv4Route(destinationAddress: prefix.address(), subnetMask: prefix.mask()))
            }
            ipv4Settings.includedRoutes = ipv4Routes
            ipv4Settings.excludedRoutes = ipv4ExcludeRoutes
            settings.ipv4Settings = ipv4Settings

            var ipv6Address: [String] = []
            var ipv6Prefixes: [NSNumber] = []
            let ipv6AddressIterator = options.getInet6Address()!
            while ipv6AddressIterator.hasNext() {
                let prefix = ipv6AddressIterator.next()!
                ipv6Address.append(prefix.address())
                ipv6Prefixes.append(NSNumber(value: prefix.prefix()))
            }
            if !ipv6Address.isEmpty {
                let ipv6Settings = NEIPv6Settings(addresses: ipv6Address, networkPrefixLengths: ipv6Prefixes)
                var ipv6Routes: [NEIPv6Route] = []
                var ipv6ExcludeRoutes: [NEIPv6Route] = []
                let inet6RouteAddressIterator = options.getInet6RouteAddress()!
                if inet6RouteAddressIterator.hasNext() {
                    while inet6RouteAddressIterator.hasNext() {
                        let prefix = inet6RouteAddressIterator.next()!
                        ipv6Routes.append(NEIPv6Route(destinationAddress: prefix.address(), networkPrefixLength: NSNumber(value: prefix.prefix())))
                    }
                } else {
                    ipv6Routes.append(NEIPv6Route.default())
                }
                let inet6RouteExcludeAddressIterator = options.getInet6RouteExcludeAddress()!
                while inet6RouteExcludeAddressIterator.hasNext() {
                    let prefix = inet6RouteExcludeAddressIterator.next()!
                    ipv6ExcludeRoutes.append(NEIPv6Route(destinationAddress: prefix.address(), networkPrefixLength: NSNumber(value: prefix.prefix())))
                }
                ipv6Settings.includedRoutes = ipv6Routes
                ipv6Settings.excludedRoutes = ipv6ExcludeRoutes
                settings.ipv6Settings = ipv6Settings
            }

            let hasDefaultRoute = ipv4Routes.contains(where: {
                $0.destinationAddress == "0.0.0.0" && $0.destinationSubnetMask == "0.0.0.0"
            })
            if !hasDefaultRoute {
                dnsSettings.matchDomains = [""]
                dnsSettings.matchDomainsNoSearch = true
            }
        }

        if options.isHTTPProxyEnabled() {
            let proxySettings = NEProxySettings()
            let proxyServer = NEProxyServer(address: options.getHTTPProxyServer(), port: Int(options.getHTTPProxyServerPort()))
            proxySettings.httpServer = proxyServer
            proxySettings.httpsServer = proxyServer
            proxySettings.httpEnabled = true
            proxySettings.httpsEnabled = true
            var bypassDomains: [String] = []
            let bypassDomainIterator = options.getHTTPProxyBypassDomain()!
            while bypassDomainIterator.hasNext() {
                bypassDomains.append(bypassDomainIterator.next())
            }
            if !bypassDomains.isEmpty {
                proxySettings.exceptionList = bypassDomains
            }
            var matchDomains: [String] = []
            let matchDomainIterator = options.getHTTPProxyMatchDomain()!
            while matchDomainIterator.hasNext() {
                matchDomains.append(matchDomainIterator.next())
            }
            if !matchDomains.isEmpty {
                proxySettings.matchDomains = matchDomains
            }
            settings.proxySettings = proxySettings
        }

        networkSettings = settings
        try await tunnel.setTunnelNetworkSettings(settings)

        if let tunFd = tunnel.packetFlow.value(forKeyPath: "socket.fileDescriptor") as? Int32 {
            ret0_.pointee = tunFd
            return
        }
        let tunFdFromLoop = LibboxGetTunnelFileDescriptor()
        if tunFdFromLoop != -1 {
            ret0_.pointee = tunFdFromLoop
        } else {
            throw RuntimeHostError("missing tun file descriptor")
        }
    }

    // MARK: Interfaces

    public func usePlatformAutoDetectControl() -> Bool {
        false
    }

    public func autoDetectControl(_: Int32) throws {}

    public func findConnectionOwner(_: Int32, sourceAddress _: String?, sourcePort _: Int32, destinationAddress _: String?, destinationPort _: Int32) throws -> LibboxConnectionOwner {
        throw RuntimeHostError("not implemented")
    }

    public func useProcFS() -> Bool {
        false
    }

    public func writeLog(_ message: String?) {
        guard let message else { return }
        TunnelLog.shared.write(message)
    }

    public func startDefaultInterfaceMonitor(_ listener: LibboxInterfaceUpdateListenerProtocol?) throws {
        guard let listener else { return }
        let monitor = NWPathMonitor()
        pathMonitor = monitor
        let semaphore = DispatchSemaphore(value: 0)
        monitor.pathUpdateHandler = { path in
            self.onUpdateDefaultInterface(listener, path)
            semaphore.signal()
            monitor.pathUpdateHandler = { path in
                self.onUpdateDefaultInterface(listener, path)
            }
        }
        monitor.start(queue: DispatchQueue.global())
        semaphore.wait()
    }

    private func onUpdateDefaultInterface(_ listener: LibboxInterfaceUpdateListenerProtocol, _ path: Network.NWPath) {
        guard path.status != .unsatisfied, let defaultInterface = path.availableInterfaces.first else {
            listener.updateDefaultInterface("", interfaceIndex: -1, isExpensive: false, isConstrained: false)
            return
        }
        listener.updateDefaultInterface(
            defaultInterface.name,
            interfaceIndex: Int32(defaultInterface.index),
            isExpensive: path.isExpensive,
            isConstrained: path.isConstrained
        )
    }

    public func closeDefaultInterfaceMonitor(_: LibboxInterfaceUpdateListenerProtocol?) throws {
        pathMonitor?.cancel()
        pathMonitor = nil
    }

    public func getInterfaces() throws -> LibboxNetworkInterfaceIteratorProtocol {
        guard let pathMonitor else {
            throw RuntimeHostError("NWPathMonitor not started")
        }
        let path = pathMonitor.currentPath
        if path.status == .unsatisfied {
            return NetworkInterfaceArray([])
        }
        var interfaces: [LibboxNetworkInterface] = []
        for item in path.availableInterfaces {
            let interface = LibboxNetworkInterface()
            interface.name = item.name
            interface.index = Int32(item.index)
            switch item.type {
            case .wifi:
                interface.type = LibboxInterfaceTypeWIFI
            case .cellular:
                interface.type = LibboxInterfaceTypeCellular
            case .wiredEthernet:
                interface.type = LibboxInterfaceTypeEthernet
            default:
                interface.type = LibboxInterfaceTypeOther
            }
            interfaces.append(interface)
        }
        return NetworkInterfaceArray(interfaces)
    }

    private final class NetworkInterfaceArray: NSObject, LibboxNetworkInterfaceIteratorProtocol {
        private var iterator: IndexingIterator<[LibboxNetworkInterface]>
        private var nextValue: LibboxNetworkInterface?

        init(_ array: [LibboxNetworkInterface]) {
            iterator = array.makeIterator()
        }

        func hasNext() -> Bool {
            nextValue = iterator.next()
            return nextValue != nil
        }

        func next() -> LibboxNetworkInterface? {
            nextValue
        }
    }

    public func underNetworkExtension() -> Bool {
        true
    }

    public func includeAllNetworks() -> Bool {
        false
    }

    public func clearDNSCache() {
        guard let networkSettings else { return }
        runBlocking {
            self.tunnel.reasserting = true
            defer { self.tunnel.reasserting = false }
            await withCheckedContinuation { continuation in
                self.tunnel.setTunnelNetworkSettings(nil) { _ in
                    continuation.resume()
                }
            }
            await withCheckedContinuation { continuation in
                self.tunnel.setTunnelNetworkSettings(networkSettings) { _ in
                    continuation.resume()
                }
            }
        }
    }

    public func readWIFIState() -> LibboxWIFIState? {
        let network = runBlocking {
            await NEHotspotNetwork.fetchCurrent()
        }
        guard let network else { return nil }
        return LibboxWIFIState(network.ssid, wifiBSSID: network.bssid)
    }

    // MARK: Command server handler

    public func serviceStop() throws {
        tunnel.stopServiceFromCommand()
    }

    public func serviceReload() throws {
        try runBlocking { [self] in
            try await tunnel.reloadService()
        }
    }

    public func getSystemProxyStatus() throws -> LibboxSystemProxyStatus {
        let status = LibboxSystemProxyStatus()
        guard let proxySettings = networkSettings?.proxySettings, proxySettings.httpServer != nil else {
            return status
        }
        status.available = true
        status.enabled = proxySettings.httpEnabled
        return status
    }

    public func setSystemProxyEnabled(_ isEnabled: Bool) throws {
        guard let networkSettings, let proxySettings = networkSettings.proxySettings, proxySettings.httpServer != nil else {
            return
        }
        if proxySettings.httpEnabled == isEnabled {
            return
        }
        proxySettings.httpEnabled = isEnabled
        proxySettings.httpsEnabled = isEnabled
        networkSettings.proxySettings = proxySettings
        try runBlocking {
            try await self.tunnel.setTunnelNetworkSettings(networkSettings)
        }
    }

    public func writeDebugMessage(_ message: String?) {
        guard let message else { return }
        TunnelLog.shared.write(message, level: 4)
    }

    public func send(_ notification: LibboxNotification?) throws {
        guard let notification else { return }
        let center = UNUserNotificationCenter.current()
        let content = UNMutableNotificationContent()
        content.title = notification.title
        content.subtitle = notification.subtitle
        content.body = notification.body
        let request = UNNotificationRequest(identifier: notification.identifier, content: content, trigger: nil)
        try runBlocking {
            try await center.add(request)
        }
    }

    public func localDNSTransport() -> (any LibboxLocalDNSTransportProtocol)? {
        nil
    }

    public func systemCertificates() -> (any LibboxStringIteratorProtocol)? {
        nil
    }

    func reset() {
        networkSettings = nil
        pathMonitor?.cancel()
        pathMonitor = nil
    }
}
