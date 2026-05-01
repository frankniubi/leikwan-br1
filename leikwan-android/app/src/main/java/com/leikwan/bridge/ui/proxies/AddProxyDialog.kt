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
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.data.ProxyNode

@Composable
fun AddUriDialog(onDismiss: () -> Unit, onAdd: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Add Proxy") },
        text = {
            OutlinedTextField(
                value = text,
                onValueChange = { text = it },
                label = { Text("ss://, vmess://, or vless:// URI") },
                modifier = Modifier.fillMaxWidth(),
                minLines = 3,
                maxLines = 6,
            )
        },
        confirmButton = {
            TextButton(onClick = { onAdd(text); onDismiss() }, enabled = text.isNotBlank()) {
                Text("Add")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
fun AddSubscriptionDialog(onDismiss: () -> Unit, onAdd: (String, String) -> Unit) {
    var name by remember { mutableStateOf("") }
    var url by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Add Subscription") },
        text = {
            Column {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("Name") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = url,
                    onValueChange = { url = it },
                    label = { Text("Subscription URL") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onAdd(name, url); onDismiss() },
                enabled = name.isNotBlank() && url.isNotBlank(),
            ) { Text("Add") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
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
        title = { Text("Modify ${node.type.uppercase()} Proxy") },
        text = {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .verticalScroll(scrollState),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text("Type: ${node.type.uppercase()}")
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("Name") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                OutlinedTextField(
                    value = server,
                    onValueChange = { server = it },
                    label = { Text("Server") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                OutlinedTextField(
                    value = port,
                    onValueChange = { port = it },
                    label = { Text("Port") },
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
                Text("Save")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
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
        is ProxyProtocolDraft.Unknown -> UnknownEditor(protocol, onProtocolChange)
    }
}

@Composable
private fun ShadowsocksEditor(
    details: ProxyProtocolDraft.Shadowsocks,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text("Shadowsocks")
    OutlinedTextField(
        value = details.method,
        onValueChange = { onChange(details.copy(method = it)) },
        label = { Text("Encrypt Method") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.password,
        onValueChange = { onChange(details.copy(password = it)) },
        label = { Text("Password / PSK") },
        modifier = Modifier.fillMaxWidth(),
        minLines = 2,
        maxLines = 3,
    )
    OutlinedTextField(
        value = details.plugin,
        onValueChange = { onChange(details.copy(plugin = it)) },
        label = { Text("Plugin") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.pluginOpts,
        onValueChange = { onChange(details.copy(pluginOpts = it)) },
        label = { Text("Plugin Options") },
        modifier = Modifier.fillMaxWidth(),
        minLines = 2,
        maxLines = 3,
    )
    OutlinedTextField(
        value = details.network,
        onValueChange = { onChange(details.copy(network = it)) },
        label = { Text("Network (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    ProtocolToggleRow(
        label = "UDP over TCP",
        checked = details.udpOverTcp,
        onCheckedChange = { onChange(details.copy(udpOverTcp = it)) },
    )
    ProtocolToggleRow(
        label = "Multiplex",
        checked = details.multiplexEnabled,
        onCheckedChange = { onChange(details.copy(multiplexEnabled = it)) },
    )
}

@Composable
private fun VlessEditor(
    details: ProxyProtocolDraft.Vless,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text("VLESS")
    OutlinedTextField(
        value = details.uuid,
        onValueChange = { onChange(details.copy(uuid = it)) },
        label = { Text("UUID") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.flow,
        onValueChange = { onChange(details.copy(flow = it)) },
        label = { Text("Flow (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.security,
        onValueChange = { onChange(details.copy(security = it)) },
        label = { Text("Security (tls / reality / none)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.transportType,
        onValueChange = { onChange(details.copy(transportType = it)) },
        label = { Text("Transport Type") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.host,
        onValueChange = { onChange(details.copy(host = it)) },
        label = { Text("Host (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.path,
        onValueChange = { onChange(details.copy(path = it)) },
        label = { Text("Path (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.sni,
        onValueChange = { onChange(details.copy(sni = it)) },
        label = { Text("SNI (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.serviceName,
        onValueChange = { onChange(details.copy(serviceName = it)) },
        label = { Text("gRPC Service Name (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.publicKey,
        onValueChange = { onChange(details.copy(publicKey = it)) },
        label = { Text("Reality Public Key (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.shortId,
        onValueChange = { onChange(details.copy(shortId = it)) },
        label = { Text("Reality Short ID (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun VmessEditor(
    details: ProxyProtocolDraft.Vmess,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text("VMess")
    OutlinedTextField(
        value = details.uuid,
        onValueChange = { onChange(details.copy(uuid = it)) },
        label = { Text("UUID") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.alterId,
        onValueChange = { onChange(details.copy(alterId = it)) },
        label = { Text("Alter ID") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.security,
        onValueChange = { onChange(details.copy(security = it)) },
        label = { Text("Security") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.network,
        onValueChange = { onChange(details.copy(network = it)) },
        label = { Text("Network") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.host,
        onValueChange = { onChange(details.copy(host = it)) },
        label = { Text("Host (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
    OutlinedTextField(
        value = details.path,
        onValueChange = { onChange(details.copy(path = it)) },
        label = { Text("Path (optional)") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun UnknownEditor(
    details: ProxyProtocolDraft.Unknown,
    onChange: (ProxyProtocolDraft) -> Unit,
) {
    Text("Unsupported protocol config")
    OutlinedTextField(
        value = details.configJson,
        onValueChange = { onChange(details.copy(configJson = it)) },
        label = { Text("Config JSON") },
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
