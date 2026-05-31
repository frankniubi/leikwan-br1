package com.leikwan.bridge.ui.proxies

import com.leikwan.bridge.data.ProxyNode

data class ProxyNodeEditDraft(
    val name: String,
    val originalServer: String,
    val originalPort: String,
    val protocol: ProxyProtocolDraft,
)

sealed interface ProxyNodeEditResult {
    data class Success(val node: ProxyNode) : ProxyNodeEditResult
    data class Error(val message: String) : ProxyNodeEditResult
}

object ProxyNodeEditor {
    fun draftFor(node: ProxyNode): ProxyNodeEditDraft {
        return ProxyNodeEditDraft(
            name = node.name,
            originalServer = node.originalServer,
            originalPort = node.originalPort.toString(),
            protocol = ProxyProtocolDraftParser.parse(node),
        )
    }

    fun applyDraft(
        node: ProxyNode,
        draft: ProxyNodeEditDraft,
    ): ProxyNodeEditResult {
        val name = draft.name.trim()
        if (name.isEmpty()) {
            return ProxyNodeEditResult.Error("Proxy name is required")
        }
        val server = draft.originalServer.trim()
        if (server.isEmpty()) {
            return ProxyNodeEditResult.Error("Proxy server is required")
        }
        val port = draft.originalPort.trim().toIntOrNull()
            ?: return ProxyNodeEditResult.Error("Proxy port must be a number")
        if (port !in 1..65535) {
            return ProxyNodeEditResult.Error("Proxy port must be between 1 and 65535")
        }
        validateProtocolDraft(draft.protocol)
            ?.let { return ProxyNodeEditResult.Error(it) }

        return ProxyNodeEditResult.Success(
            node.copy(
                name = name,
                originalServer = server,
                originalPort = port,
                configJson = ProxyProtocolDraftParser.serialize(
                    type = node.type,
                    protocol = draft.protocol,
                    name = name,
                    server = server,
                    port = port,
                ),
            )
        )
    }

    private fun validateProtocolDraft(protocol: ProxyProtocolDraft): String? {
        return when (protocol) {
            is ProxyProtocolDraft.Shadowsocks -> when {
                protocol.method.isBlank() -> "Shadowsocks method is required"
                protocol.password.isBlank() -> "Shadowsocks password is required"
                else -> null
            }

            is ProxyProtocolDraft.Vless -> when {
                protocol.uuid.isBlank() -> "VLESS UUID is required"
                else -> null
            }

            is ProxyProtocolDraft.Vmess -> when {
                protocol.uuid.isBlank() -> "VMess UUID is required"
                protocol.alterId.isBlank() -> "VMess alterId is required"
                protocol.alterId.toIntOrNull() == null -> "VMess alterId must be a number"
                else -> null
            }

            is ProxyProtocolDraft.WireGuard -> when {
                protocol.privateKey.isBlank() -> "WireGuard private key is required"
                protocol.addresses.isBlank() -> "WireGuard address is required"
                protocol.publicKey.isBlank() -> "WireGuard peer public key is required"
                protocol.allowedIps.isBlank() -> "WireGuard allowed IPs are required"
                protocol.mtu.isNotBlank() && protocol.mtu.toIntOrNull() == null -> "WireGuard MTU must be a number"
                protocol.persistentKeepalive.isNotBlank() &&
                    protocol.persistentKeepalive.toIntOrNull() == null -> "WireGuard keepalive must be a number"
                else -> null
            }

            is ProxyProtocolDraft.Unknown -> null
        }
    }
}
