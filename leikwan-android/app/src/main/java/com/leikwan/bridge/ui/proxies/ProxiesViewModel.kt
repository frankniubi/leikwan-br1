package com.leikwan.bridge.ui.proxies

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.Subscription
import com.leikwan.bridge.proxy.ImportRouter
import com.leikwan.bridge.proxy.ProxyUriParser
import com.leikwan.bridge.proxy.SubscriptionFetcher
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

data class ProxiesUiState(
    val nodes: List<ProxyNode> = emptyList(),
    val subscriptions: List<Subscription> = emptyList(),
    val isLoading: Boolean = false,
    val error: String? = null,
)

class ProxiesViewModel(app: Application) : AndroidViewModel(app) {
    private val db = AppDatabase.get(app)
    private val nodeDao = db.proxyNodeDao()
    private val subDao = db.subscriptionDao()

    private val _state = MutableStateFlow(ProxiesUiState())
    val state = _state.asStateFlow()

    private val _snackbar = MutableStateFlow<String?>(null)
    val snackbar = _snackbar.asStateFlow()

    fun clearSnackbar() {
        _snackbar.value = null
    }

    init {
        viewModelScope.launch {
            nodeDao.getAll().collectLatest { nodes ->
                _state.value = _state.value.copy(nodes = nodes)
            }
        }
        viewModelScope.launch {
            subDao.getAll().collectLatest { subs ->
                _state.value = _state.value.copy(subscriptions = subs)
            }
        }
    }

    fun addFromUri(uriText: String) {
        viewModelScope.launch {
            val nodes = uriText.lines().mapNotNull { ProxyUriParser.parse(it.trim()) }
            if (nodes.isEmpty()) {
                _state.value = _state.value.copy(error = "No valid proxy URIs found")
                return@launch
            }
            nodeDao.insertAll(nodes)
            _state.value = _state.value.copy(error = null)
        }
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
        viewModelScope.launch { nodeDao.update(node.copy(enabled = !node.enabled)) }
    }

    fun deleteNode(node: ProxyNode) {
        viewModelScope.launch { nodeDao.delete(node) }
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
