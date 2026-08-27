package com.leikwan.bridge.ui.proxies

import androidx.annotation.StringRes
import com.leikwan.bridge.R
import com.leikwan.bridge.data.ProxyNode

data class ProxyNodeEditDraft(
    val name: String,
    val originalServer: String,
    val originalPort: String,
    val protocol: ProxyProtocolDraft,
)

sealed interface ProxyNodeEditResult {
    data class Success(val node: ProxyNode) : ProxyNodeEditResult
    data class Error(@StringRes val messageRes: Int) : ProxyNodeEditResult
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
            return ProxyNodeEditResult.Error(R.string.proxy_error_name_required)
        }
        val server = draft.originalServer.trim()
        if (server.isEmpty()) {
            return ProxyNodeEditResult.Error(R.string.proxy_error_server_required)
        }
        val port = draft.originalPort.trim().toIntOrNull()
            ?: return ProxyNodeEditResult.Error(R.string.proxy_error_port_number)
        if (port !in 1..65535) {
            return ProxyNodeEditResult.Error(R.string.proxy_error_port_range)
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

    @StringRes
    private fun validateProtocolDraft(protocol: ProxyProtocolDraft): Int? {
        return when (protocol) {
            is ProxyProtocolDraft.Shadowsocks -> when {
                protocol.method.isBlank() -> R.string.proxy_error_ss_method_required
                protocol.password.isBlank() -> R.string.proxy_error_ss_password_required
                else -> null
            }

            is ProxyProtocolDraft.Vless -> when {
                protocol.uuid.isBlank() -> R.string.proxy_error_vless_uuid_required
                else -> null
            }

            is ProxyProtocolDraft.Vmess -> when {
                protocol.uuid.isBlank() -> R.string.proxy_error_vmess_uuid_required
                protocol.alterId.isBlank() -> R.string.proxy_error_vmess_alter_id_required
                protocol.alterId.toIntOrNull() == null -> R.string.proxy_error_vmess_alter_id_number
                else -> null
            }

            is ProxyProtocolDraft.WireGuard -> when {
                protocol.privateKey.isBlank() -> R.string.proxy_error_wg_private_key_required
                protocol.addresses.isBlank() -> R.string.proxy_error_wg_address_required
                protocol.publicKey.isBlank() -> R.string.proxy_error_wg_peer_public_key_required
                protocol.allowedIps.isBlank() -> R.string.proxy_error_wg_allowed_ips_required
                protocol.mtu.isNotBlank() && protocol.mtu.toIntOrNull() == null -> R.string.proxy_error_wg_mtu_number
                protocol.persistentKeepalive.isNotBlank() &&
                    protocol.persistentKeepalive.toIntOrNull() == null -> R.string.proxy_error_wg_keepalive_number
                else -> null
            }

            is ProxyProtocolDraft.Socks5 -> null

            is ProxyProtocolDraft.Unknown -> null
        }
    }
}
