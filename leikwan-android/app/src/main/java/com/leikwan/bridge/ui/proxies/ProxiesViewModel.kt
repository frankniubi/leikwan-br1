package com.leikwan.bridge.ui.proxies

import android.app.Application
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.Subscription
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.hosted.HostedBulkUploadResult
import com.leikwan.bridge.data.hosted.HostedNodeException
import com.leikwan.bridge.data.hosted.HostedNodeIdentity
import com.leikwan.bridge.data.hosted.HostedNodePairingStore
import com.leikwan.bridge.data.hosted.HostedNodeSync
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.CancellationException
import com.leikwan.bridge.proxy.ImportRouter
import com.leikwan.bridge.proxy.ProxyUriParser
import com.leikwan.bridge.proxy.SubscriptionFetcher
import com.leikwan.bridge.service.ProxyConnectionMode
import com.leikwan.bridge.service.TunnelOrchestrator
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

data class ProxiesUiState(
    val nodes: List<ProxyNode> = emptyList(),
    val subscriptions: List<Subscription> = emptyList(),
    val selectedProxyId: Long = 0L,
    val connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
    val routingSelectorEnabled: Boolean = false,
    val isLoading: Boolean = false,
    val error: String? = null,
)

class ProxiesViewModel(app: Application) : AndroidViewModel(app) {
    private val appContext = app.applicationContext
    private val prefs = Prefs.get(app)
    private val db = AppDatabase.get(app)
    private val nodeDao = db.proxyNodeDao()
    private val subDao = db.subscriptionDao()
    private val orchestrator = TunnelOrchestrator.forApplication(app)
    /** Hosted-node sync for this client; created on first use so plain node edits never build it. */
    private val hosted: HostedNodeSync by lazy { PanelRepository(app).hostedNodes }
    private val _state = MutableStateFlow(
        ProxiesUiState(
            selectedProxyId = prefs.selectedProxyId,
            connectionMode = prefs.proxyConnectionMode,
            routingSelectorEnabled = prefs.routingSelectorEnabled,
        )
    )
    val state = _state.asStateFlow()

    private val _snackbar = MutableStateFlow<String?>(null)
    val snackbar = _snackbar.asStateFlow()

    fun clearSnackbar() {
        _snackbar.value = null
    }

    init {
        viewModelScope.launch {
            nodeDao.getAll().collectLatest { nodes ->
                val selectedId = _state.value.selectedProxyId.takeIf { selected ->
                    nodes.any { it.id == selected }
                } ?: 0L
                if (selectedId == 0L && prefs.selectedProxyId != 0L) {
                    prefs.selectedProxyId = 0L
                }
                _state.value = _state.value.copy(nodes = nodes, selectedProxyId = selectedId)
            }
        }
        viewModelScope.launch {
            subDao.getAll().collectLatest { subs ->
                _state.value = _state.value.copy(subscriptions = subs)
            }
        }
    }

    fun addFromUri(uriText: String) {
        importAny(uriText)
    }

    fun addSubscription(name: String, url: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                val subId = subDao.insert(Subscription(name = name, url = url))
                val nodes = SubscriptionFetcher.fetch(url).map { it.copy(subscriptionId = subId) }
                insertLocal(nodes)
                subDao.update(subDao.getById(subId)!!.copy(lastUpdated = System.currentTimeMillis()))
                _state.value = _state.value.copy(isLoading = false)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message)
            }
        }
    }

    fun refreshSubscription(sub: Subscription) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                nodeDao.deleteBySubscription(sub.id)
                val nodes = SubscriptionFetcher.fetch(sub.url).map { it.copy(subscriptionId = sub.id) }
                insertLocal(nodes)
                subDao.update(sub.copy(lastUpdated = System.currentTimeMillis()))
                _state.value = _state.value.copy(isLoading = false)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message)
            }
        }
    }

    fun toggleNode(node: ProxyNode) {
        viewModelScope.launch {
            val updated = node.copy(enabled = !node.enabled)
            nodeDao.update(updated)
            if (!updated.enabled && prefs.selectedProxyId == node.id && prefs.proxyConnectionMode == ProxyConnectionMode.DIRECT) {
                orchestrator.stop()
            }
        }
    }

    fun selectNode(node: ProxyNode) {
        viewModelScope.launch {
            if (!node.enabled) {
                nodeDao.update(node.copy(enabled = true))
            }
            prefs.selectedProxyId = node.id
            _state.value = _state.value.copy(selectedProxyId = node.id, error = null)
        }
    }

    fun setConnectionMode(mode: ProxyConnectionMode) {
        prefs.proxyConnectionMode = mode
        _state.value = _state.value.copy(connectionMode = mode, error = null)
        if (mode == ProxyConnectionMode.SERVICES) {
            orchestrator.stop()
        }
    }

    fun deleteNode(node: ProxyNode) {
        viewModelScope.launch {
            if (node.hostedId != null) {
                // A hosted node is deleted for every device of the client, panel first.
                if (!runHosted { hosted.delete(node) }) return@launch
                _snackbar.value = s(R.string.hosted_node_deleted)
            } else {
                nodeDao.delete(node)
            }
            if (prefs.selectedProxyId == node.id) {
                prefs.selectedProxyId = 0L
                _state.value = _state.value.copy(selectedProxyId = 0L)
            }
        }
    }

    fun updateNode(node: ProxyNode, draft: ProxyNodeEditDraft) {
        viewModelScope.launch {
            when (val result = ProxyNodeEditor.applyDraft(node, draft)) {
                is ProxyNodeEditResult.Success -> {
                    if (node.hostedId != null) {
                        // Write-through: the panel accepts the edit first, then every device syncs it.
                        if (runHosted { hosted.update(result.node) }) {
                            _state.value = _state.value.copy(error = null)
                            _snackbar.value = s(R.string.proxy_updated)
                        }
                        return@launch
                    }
                    nodeDao.update(result.node)
                    _state.value = _state.value.copy(error = null)
                    _snackbar.value = s(R.string.proxy_updated)
                }
                is ProxyNodeEditResult.Error -> {
                    _state.value = _state.value.copy(error = s(result.messageRes))
                }
            }
        }
    }

    /** Cloud nodes hidden on this device only (server id -> name), for the restore list. */
    val hiddenCloudNodes: StateFlow<Map<Long, String>> = HostedNodePairingStore.from(app).suppressed

    /** Hides a cloud node on this device only; the client's other devices keep it. */
    fun removeFromThisDevice(node: ProxyNode) {
        viewModelScope.launch {
            if (!runHosted { hosted.removeFromThisDevice(node) }) return@launch
            if (prefs.selectedProxyId == node.id) {
                prefs.selectedProxyId = 0L
                _state.value = _state.value.copy(selectedProxyId = 0L)
            }
            _snackbar.value = s(R.string.hosted_node_removed_local)
        }
    }

    /** Restores hidden cloud nodes on this device (all of them when [hostedIds] is null). */
    fun restoreHiddenCloudNodes(hostedIds: Collection<Long>? = null) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            val ok = runHosted {
                if (hostedIds == null) hosted.restoreHidden() else hosted.restoreHidden(hostedIds)
            }
            _state.value = _state.value.copy(isLoading = false)
            if (ok) _snackbar.value = s(R.string.hosted_node_restored)
        }
    }

    /** Local -> cloud for one node: it becomes hosted and reaches every device of the client. */
    fun uploadToCloud(node: ProxyNode) {
        viewModelScope.launch {
            if (runHosted { hosted.upload(node) }) _snackbar.value = s(R.string.hosted_node_uploaded)
        }
    }

    /** Local -> cloud for every local Shadowsocks / SOCKS5 / VLESS node. */
    fun syncAllToCloud() {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            var result: HostedBulkUploadResult? = null
            runHosted { result = hosted.uploadAllLocal() }
            _state.value = _state.value.copy(isLoading = false)
            result?.let {
                _snackbar.value = s(R.string.hosted_node_sync_all_result, it.uploaded, it.alreadyHosted, it.unsupported, it.failed)
            }
        }
    }

    /** Cloud -> local on demand (the config pull also does this whenever the panel revision moves). */
    fun refreshFromCloud() {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            val ok = runHosted { hosted.refresh() }
            _state.value = _state.value.copy(isLoading = false)
            if (ok) _snackbar.value = s(R.string.hosted_node_refreshed)
        }
    }

    private suspend fun runHosted(block: suspend () -> Unit): Boolean = try {
        block()
        true
    } catch (e: CancellationException) {
        throw e
    } catch (e: HostedNodeException) {
        _state.value = _state.value.copy(error = s(R.string.hosted_node_error_format, listOfNotNull(e.code, e.field).joinToString(": ")))
        false
    } catch (e: Exception) {
        _state.value = _state.value.copy(error = s(R.string.hosted_node_error_format, e.message.orEmpty()))
        false
    }

    /** Imports never duplicate a node the client already hosts. */
    private suspend fun insertLocal(nodes: List<ProxyNode>): List<ProxyNode> {
        val fresh = HostedNodeIdentity.withoutHostedDuplicates(nodes, nodeDao.getHosted())
        nodeDao.insertAll(fresh)
        return fresh
    }

    fun duplicateNode(node: ProxyNode) {
        viewModelScope.launch {
            val duplicated = ProxyNodeDuplicator.duplicate(node)
            val newId = nodeDao.insert(duplicated)
            prefs.selectedProxyId = newId
            _state.value = _state.value.copy(selectedProxyId = newId, error = null)
            _snackbar.value = s(R.string.proxy_duplicated)
        }
    }

    fun startDirectSelected() {
        viewModelScope.launch {
            if (prefs.proxyConnectionMode != ProxyConnectionMode.DIRECT) return@launch
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                orchestrator.startForForward(0L)
                _state.value = _state.value.copy(isLoading = false, error = null)
                _snackbar.value = s(R.string.direct_vpn_started)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message)
            }
        }
    }

    fun refreshRoutingSelectorState() {
        _state.value = _state.value.copy(routingSelectorEnabled = prefs.routingSelectorEnabled)
    }

    fun deleteSubscription(sub: Subscription) {
        viewModelScope.launch {
            nodeDao.deleteBySubscription(sub.id)
            subDao.delete(sub)
        }
    }

    fun clearError() {
        _state.value = _state.value.copy(error = null)
    }

    fun importAny(text: String) {
        viewModelScope.launch {
            when (val result = ImportRouter.process(text)) {
                is ImportRouter.ImportResult.ProxyNodes -> {
                    insertLocal(result.nodes)
                    _snackbar.value = s(R.string.added_proxy_nodes_format, result.nodes.size)
                }
                is ImportRouter.ImportResult.SubscriptionUrl -> {
                    _state.value = _state.value.copy(isLoading = true)
                    try {
                        val url = result.url
                        val name = url.substringAfterLast("/").take(16).ifEmpty { s(R.string.default_subscription_name) }
                        val subId = subDao.insert(Subscription(name = name, url = url))
                        val nodes = SubscriptionFetcher.fetch(url)
                            .map { it.copy(subscriptionId = subId) }
                        insertLocal(nodes)
                        subDao.update(subDao.getById(subId)!!.copy(
                            lastUpdated = System.currentTimeMillis()
                        ))
                        _snackbar.value = s(R.string.subscription_added_format, nodes.size)
                        _state.value = _state.value.copy(isLoading = false)
                    } catch (e: Exception) {
                        _state.value = _state.value.copy(isLoading = false)
                        _snackbar.value = s(R.string.subscription_fetch_failed_format, e.message.orEmpty())
                    }
                }
                is ImportRouter.ImportResult.Mixed -> {
                    insertLocal(result.nodes)
                    for (url in result.subscriptionUrls) {
                        try {
                            val name = url.substringAfterLast("/").take(16).ifEmpty { s(R.string.default_subscription_name) }
                            val subId = subDao.insert(Subscription(name = name, url = url))
                            val nodes = SubscriptionFetcher.fetch(url)
                                .map { it.copy(subscriptionId = subId) }
                            insertLocal(nodes)
                            subDao.update(subDao.getById(subId)!!.copy(
                                lastUpdated = System.currentTimeMillis()
                            ))
                        } catch (_: Exception) {}
                    }
                    _snackbar.value = s(
                        R.string.imported_proxy_subscription_format,
                        result.nodes.size,
                        result.subscriptionUrls.size,
                    )
                }
                is ImportRouter.ImportResult.Empty -> {
                    _snackbar.value = s(R.string.no_proxy_links_found)
                }
            }
        }
    }

    private fun s(@StringRes id: Int, vararg args: Any): String =
        appContext.getString(id, *args)
}
