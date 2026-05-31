package com.leikwan.bridge.ui.proxies

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.Subscription
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
    private val prefs = Prefs.get(app)
    private val db = AppDatabase.get(app)
    private val nodeDao = db.proxyNodeDao()
    private val subDao = db.subscriptionDao()
    private val orchestrator = TunnelOrchestrator(app)

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
                nodeDao.insertAll(nodes)
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
                nodeDao.insertAll(nodes)
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
        _state.value = _state.value.copy(connectionMode = mode)
        if (mode == ProxyConnectionMode.SERVICES) {
            orchestrator.stop()
        }
    }

    fun deleteNode(node: ProxyNode) {
        viewModelScope.launch {
            nodeDao.delete(node)
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
                    nodeDao.update(result.node)
                    _state.value = _state.value.copy(error = null)
                    _snackbar.value = "Proxy updated"
                }
                is ProxyNodeEditResult.Error -> {
                    _state.value = _state.value.copy(error = result.message)
                }
            }
        }
    }

    fun duplicateNode(node: ProxyNode) {
        viewModelScope.launch {
            val duplicated = ProxyNodeDuplicator.duplicate(node)
            val newId = nodeDao.insert(duplicated)
            prefs.selectedProxyId = newId
            _state.value = _state.value.copy(selectedProxyId = newId, error = null)
            _snackbar.value = "Proxy duplicated"
        }
    }

    fun startDirectSelected() {
        viewModelScope.launch {
            if (prefs.proxyConnectionMode != ProxyConnectionMode.DIRECT) return@launch
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                orchestrator.startForForward(0L)
                _state.value = _state.value.copy(isLoading = false, error = null)
                _snackbar.value = "Direct VPN started"
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message)
            }
        }
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

    fun refreshRoutingSelectorState() {
        _state.value = _state.value.copy(routingSelectorEnabled = prefs.routingSelectorEnabled)
    }

    fun importAny(text: String) {
        viewModelScope.launch {
            when (val result = ImportRouter.process(text)) {
                is ImportRouter.ImportResult.ProxyNodes -> {
                    nodeDao.insertAll(result.nodes)
                    _snackbar.value = "Added ${result.nodes.size} proxy node(s)"
                }
                is ImportRouter.ImportResult.SubscriptionUrl -> {
                    _state.value = _state.value.copy(isLoading = true)
                    try {
                        val url = result.url
                        val name = url.substringAfterLast("/").take(16).ifEmpty { "Subscription" }
                        val subId = subDao.insert(Subscription(name = name, url = url))
                        val nodes = SubscriptionFetcher.fetch(url)
                            .map { it.copy(subscriptionId = subId) }
                        nodeDao.insertAll(nodes)
                        subDao.update(subDao.getById(subId)!!.copy(
                            lastUpdated = System.currentTimeMillis()
                        ))
                        _snackbar.value = "Subscription added (${nodes.size} nodes)"
                        _state.value = _state.value.copy(isLoading = false)
                    } catch (e: Exception) {
                        _state.value = _state.value.copy(isLoading = false)
                        _snackbar.value = "Failed to fetch subscription: ${e.message}"
                    }
                }
                is ImportRouter.ImportResult.Mixed -> {
                    nodeDao.insertAll(result.nodes)
                    for (url in result.subscriptionUrls) {
                        try {
                            val name = url.substringAfterLast("/").take(16).ifEmpty { "Subscription" }
                            val subId = subDao.insert(Subscription(name = name, url = url))
                            val nodes = SubscriptionFetcher.fetch(url)
                                .map { it.copy(subscriptionId = subId) }
                            nodeDao.insertAll(nodes)
                            subDao.update(subDao.getById(subId)!!.copy(
                                lastUpdated = System.currentTimeMillis()
                            ))
                        } catch (_: Exception) {}
                    }
                    _snackbar.value = "Imported ${result.nodes.size} node(s) and ${result.subscriptionUrls.size} subscription(s)"
                }
                is ImportRouter.ImportResult.Empty -> {
                    _snackbar.value = "No proxy links found"
                }
            }
        }
    }
}
