package com.leikwan.bridge.ui.proxies

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R
import com.leikwan.bridge.data.ProxyNode

@Composable
fun AddUriDialog(onDismiss: () -> Unit, onAdd: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.add_proxy_title)) },
        text = {
            OutlinedTextField(
                value = text,
                onValueChange = { text = it },
                label = { Text(stringResource(R.string.add_proxy_uri_label)) },
                modifier = Modifier.fillMaxWidth(),
                minLines = 3,
                maxLines = 6,
            )
        },
        confirmButton = {
            TextButton(onClick = { onAdd(text); onDismiss() }, enabled = text.isNotBlank()) {
                Text(stringResource(R.string.common_add))
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel)) } },
    )
}

@Composable
fun AddSubscriptionDialog(onDismiss: () -> Unit, onAdd: (String, String) -> Unit) {
    var name by remember { mutableStateOf("") }
    var url by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.add_subscription_title)) },
        text = {
            Column {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text(stringResource(R.string.field_name)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = url,
                    onValueChange = { url = it },
                    label = { Text(stringResource(R.string.field_subscription_url)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onAdd(name, url); onDismiss() },
                enabled = name.isNotBlank() && url.isNotBlank(),
            ) { Text(stringResource(R.string.common_add)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel)) } },
    )
}

@Composable
fun EditProxyDialog(
    node: ProxyNode,
    onDismiss: () -> Unit,
    onSave: (ProxyNodeEditDraft) -> Unit,
) {
    val initialDraft = remember(node.id) { ProxyNodeEditor.draftFor(node) }
    var name by remember(node.id) { mutableStateOf(initialDraft.name) }
    var server by remember(node.id) { mutableStateOf(initialDraft.originalServer) }
    var port by remember(node.id) { mutableStateOf(initialDraft.originalPort) }
    var protocol by remember(node.id) { mutableStateOf(initialDraft.protocol) }
    val scrollState = rememberScrollState()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.edit_proxy_title_format, node.type.uppercase())) },
        text = {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .verticalScroll(scrollState),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text(stringResource(R.string.proxy_type_format, node.type.uppercase()))
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text(stringResource(R.string.field_name)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                OutlinedTextField(
                    value = server,
                    onValueChange = { server = it },
                    label = { Text(stringResource(R.string.field_server)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                OutlinedTextField(
                    value = port,
                    onValueChange = { port = it },
                    label = { Text(stringResource(R.string.field_port)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                ProxyProtocolEditor(
                    protocol = protocol,
                    onProtocolChange = { protocol = it },
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    onSave(
                        ProxyNodeEditDraft(
                            name = name,
                            originalServer = server,
                            originalPort = port,
                            protocol = protocol,
                        )
                    )
                },
                enabled = name.isNotBlank() && server.isNotBlank() && port.isNotBlank(),
            ) {
                Text(stringResource(R.string.common_save))
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel)) } },
    )
}

@Composable
private fun ProxyProtocolEditor(
    protocol: ProxyProtocolDraft,
    onProtocolChange: (ProxyProtocolDraft) -> Unit,
) {
    when (protocol) {
        is ProxyProtocolDraft.Shadowsocks -> ShadowsocksEditor(protocol, onProtocolChange)
        is ProxyProtocolDraft.Vless -> VlessEditor(protocol, onProtocolChange)
        is ProxyProtocolDraft.Vmess -> VmessEditor(protocol, onProtocolChange)
        is ProxyProtocolDraft.WireGuard -> WireGuardEditor(protocol, onProtocolChange)
        is ProxyProtocolDraft.Socks5 -> Socks5Editor(protocol, onProtocolChange)
        is ProxyProtocolDraft.Unknown -> UnknownEditor(protocol, onProtocolChange)
    }
}

@Composable
private fun ShadowsocksEditor(
    details: ProxyProtocolDraft.Shadowsocks,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text(stringResource(R.string.protocol_shadowsocks))
    OutlinedTextField(
        value = details.method,
        onValueChange = { onChange(details.copy(method = it)) },
        label = { Text(stringResource(R.string.field_encrypt_method)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.password,
        onValueChange = { onChange(details.copy(password = it)) },
        label = { Text(stringResource(R.string.field_password_psk)) },
        modifier = Modifier.fillMaxWidth(),
        minLines = 2,
        maxLines = 3,
    )
    OutlinedTextField(
        value = details.plugin,
        onValueChange = { onChange(details.copy(plugin = it)) },
        label = { Text(stringResource(R.string.field_plugin)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.pluginOpts,
        onValueChange = { onChange(details.copy(pluginOpts = it)) },
        label = { Text(stringResource(R.string.field_plugin_options)) },
        modifier = Modifier.fillMaxWidth(),
        minLines = 2,
        maxLines = 3,
    )
    OutlinedTextField(
        value = details.network,
        onValueChange = { onChange(details.copy(network = it)) },
        label = { Text(stringResource(R.string.field_network_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    ProtocolToggleRow(
        label = stringResource(R.string.toggle_udp_over_tcp),
        checked = details.udpOverTcp,
        onCheckedChange = { onChange(details.copy(udpOverTcp = it)) },
    )
    ProtocolToggleRow(
        label = stringResource(R.string.toggle_multiplex),
        checked = details.multiplexEnabled,
        onCheckedChange = { onChange(details.copy(multiplexEnabled = it)) },
    )
}

@Composable
private fun VlessEditor(
    details: ProxyProtocolDraft.Vless,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text(stringResource(R.string.protocol_vless))
    OutlinedTextField(
        value = details.uuid,
        onValueChange = { onChange(details.copy(uuid = it)) },
        label = { Text(stringResource(R.string.field_uuid)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.flow,
        onValueChange = { onChange(details.copy(flow = it)) },
        label = { Text(stringResource(R.string.field_flow_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.security,
        onValueChange = { onChange(details.copy(security = it)) },
        label = { Text(stringResource(R.string.field_security_vless)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.transportType,
        onValueChange = { onChange(details.copy(transportType = it)) },
        label = { Text(stringResource(R.string.field_transport_type)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.host,
        onValueChange = { onChange(details.copy(host = it)) },
        label = { Text(stringResource(R.string.field_host_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.path,
        onValueChange = { onChange(details.copy(path = it)) },
        label = { Text(stringResource(R.string.field_path_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.sni,
        onValueChange = { onChange(details.copy(sni = it)) },
        label = { Text(stringResource(R.string.field_sni_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.serviceName,
        onValueChange = { onChange(details.copy(serviceName = it)) },
        label = { Text(stringResource(R.string.field_grpc_service_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.publicKey,
        onValueChange = { onChange(details.copy(publicKey = it)) },
        label = { Text(stringResource(R.string.field_reality_public_key_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.shortId,
        onValueChange = { onChange(details.copy(shortId = it)) },
        label = { Text(stringResource(R.string.field_reality_short_id_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun VmessEditor(
    details: ProxyProtocolDraft.Vmess,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text(stringResource(R.string.protocol_vmess))
    OutlinedTextField(
        value = details.uuid,
        onValueChange = { onChange(details.copy(uuid = it)) },
        label = { Text(stringResource(R.string.field_uuid)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.alterId,
        onValueChange = { onChange(details.copy(alterId = it)) },
        label = { Text(stringResource(R.string.field_alter_id)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.security,
        onValueChange = { onChange(details.copy(security = it)) },
        label = { Text(stringResource(R.string.field_security)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.network,
        onValueChange = { onChange(details.copy(network = it)) },
        label = { Text(stringResource(R.string.field_network)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.host,
        onValueChange = { onChange(details.copy(host = it)) },
        label = { Text(stringResource(R.string.field_host_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.path,
        onValueChange = { onChange(details.copy(path = it)) },
        label = { Text(stringResource(R.string.field_path_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun WireGuardEditor(
    details: ProxyProtocolDraft.WireGuard,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text(stringResource(R.string.protocol_wireguard))
    OutlinedTextField(
        value = details.privateKey,
        onValueChange = { onChange(details.copy(privateKey = it)) },
        label = { Text(stringResource(R.string.field_private_key)) },
        modifier = Modifier.fillMaxWidth(),
        minLines = 2,
        maxLines = 3,
    )
    OutlinedTextField(
        value = details.addresses,
        onValueChange = { onChange(details.copy(addresses = it)) },
        label = { Text(stringResource(R.string.field_interface_addresses)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.publicKey,
        onValueChange = { onChange(details.copy(publicKey = it)) },
        label = { Text(stringResource(R.string.field_peer_public_key)) },
        modifier = Modifier.fillMaxWidth(),
        minLines = 2,
        maxLines = 3,
    )
    OutlinedTextField(
        value = details.preSharedKey,
        onValueChange = { onChange(details.copy(preSharedKey = it)) },
        label = { Text(stringResource(R.string.field_preshared_key_optional)) },
        modifier = Modifier.fillMaxWidth(),
        minLines = 1,
        maxLines = 2,
    )
    OutlinedTextField(
        value = details.allowedIps,
        onValueChange = { onChange(details.copy(allowedIps = it)) },
        label = { Text(stringResource(R.string.field_allowed_ips)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.mtu,
        onValueChange = { onChange(details.copy(mtu = it)) },
        label = { Text(stringResource(R.string.field_mtu_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.persistentKeepalive,
        onValueChange = { onChange(details.copy(persistentKeepalive = it)) },
        label = { Text(stringResource(R.string.field_persistent_keepalive_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.reserved,
        onValueChange = { onChange(details.copy(reserved = it)) },
        label = { Text(stringResource(R.string.field_reserved_bytes_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun Socks5Editor(
    details: ProxyProtocolDraft.Socks5,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text(stringResource(R.string.protocol_socks5))
    OutlinedTextField(
        value = details.username,
        onValueChange = { onChange(details.copy(username = it)) },
        label = { Text(stringResource(R.string.field_username_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.password,
        onValueChange = { onChange(details.copy(password = it)) },
        label = { Text(stringResource(R.string.field_password_optional)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun UnknownEditor(
    details: ProxyProtocolDraft.Unknown,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text(stringResource(R.string.unsupported_protocol_config))
    OutlinedTextField(
        value = details.configJson,
        onValueChange = { onChange(details.copy(configJson = it)) },
        label = { Text(stringResource(R.string.field_config_json)) },
        modifier = Modifier.fillMaxWidth(),
        minLines = 6,
        maxLines = 10,
    )
}

@Composable
private fun ProtocolToggleRow(
    label: String,
    checked: Boolean,
    onCheckedChange: (Boolean) -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(label)
        Switch(
            checked = checked,
            onCheckedChange = onCheckedChange,
        )
    }
}
