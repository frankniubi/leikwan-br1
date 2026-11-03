package com.leikwan.bridge.ui.approuting

import android.app.Application
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.core.graphics.drawable.toBitmap
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.routing.AppGroup
import com.leikwan.bridge.routing.AppPolicy
import com.leikwan.bridge.routing.AppPresetCatalog
import com.leikwan.bridge.routing.AppRoutingConfig
import com.leikwan.bridge.routing.AppVpnScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.UUID

data class InstalledApp(
    val packageName: String,
    val label: String,
    val icon: ImageBitmap?,
)

data class AppGroupUi(
    val key: String,
    val labelEn: String,
    val labelZh: String,
    val apps: List<InstalledApp>,
)

/** A line (forward) an app group can ride, with the node paired to it on the Services page. */
data class AppGroupLine(
    val forwardId: Long,
    val label: String,
    val pairedNodeId: Long?,
)

data class AppRoutingUiState(
    val config: AppRoutingConfig = AppRoutingConfig(),
    val groups: List<AppGroupUi> = emptyList(),
    val otherApps: List<InstalledApp> = emptyList(),
    val nodes: List<ProxyNode> = emptyList(),
    val query: String = "",
    val isLoading: Boolean = true,
)

class AppRoutingViewModel(app: Application) : AndroidViewModel(app) {
    private val prefs = Prefs.get(app)
    private val mutable = MutableStateFlow(AppRoutingUiState(config = prefs.appRouting))
    val state = mutable.asStateFlow()

    init {
        viewModelScope.launch {
            val (groups, others) = withContext(Dispatchers.IO) { loadApps() }
            val nodes = withContext(Dispatchers.IO) {
                runCatching { AppDatabase.get(app).proxyNodeDao().getEnabled() }.getOrDefault(emptyList())
            }
            mutable.update { it.copy(groups = groups, otherApps = others, nodes = nodes, isLoading = false) }
        }
    }

    fun setQuery(query: String) = mutable.update { it.copy(query = query) }

    fun setScope(scope: AppVpnScope) = save { config ->
        // Keep each app's explicit choice; only the default for unlisted apps changes.
        config.copy(scope = scope)
    }

    fun setPolicy(packageName: String, policy: AppPolicy, nodeId: Long? = null) = save { config ->
        config.with(packageName, policy, nodeId)
    }

    fun setGroupPolicy(groupKey: String, policy: AppPolicy) = save { config ->
        val group = mutable.value.groups.firstOrNull { it.key == groupKey } ?: return@save config
        group.apps.fold(config) { next, app -> next.with(app.packageName, policy) }
    }

    /** Moves one app into the user's app group [groupId]. */
    fun setAppGroup(packageName: String, groupId: String) = save { config ->
        config.withGroupMember(packageName, groupId)
    }

    /** Moves every installed app of a preset ([presetKey]) into the user's app group [groupId]. */
    fun setPresetAppGroup(presetKey: String, groupId: String) = save { config ->
        val preset = mutable.value.groups.firstOrNull { it.key == presetKey } ?: return@save config
        preset.apps.fold(config) { next, app -> next.withGroupMember(app.packageName, groupId) }
    }

    /** Creates ([group] id blank) or replaces a user app group. */
    fun saveAppGroup(group: AppGroup) = save { config ->
        config.withGroup(if (group.id.isBlank()) group.copy(id = UUID.randomUUID().toString()) else group)
    }

    fun deleteAppGroup(groupId: String) = save { config -> config.withoutGroup(groupId) }

    private fun save(change: (AppRoutingConfig) -> AppRoutingConfig) {
        val next = change(mutable.value.config)
        prefs.appRouting = next
        mutable.update { it.copy(config = next) }
    }

    private fun loadApps(): Pair<List<AppGroupUi>, List<InstalledApp>> {
        val pm = getApplication<Application>().packageManager
        val iconPx = (40 * getApplication<Application>().resources.displayMetrics.density).toInt().coerceAtLeast(48)
        val cache = HashMap<String, InstalledApp?>()
        fun appFor(packageName: String): InstalledApp? = cache.getOrPut(packageName) {
            val info = runCatching { pm.getApplicationInfo(packageName, 0) }.getOrNull() ?: return@getOrPut null
            InstalledApp(
                packageName = packageName,
                label = runCatching { pm.getApplicationLabel(info).toString() }.getOrDefault(packageName),
                icon = runCatching { pm.getApplicationIcon(info).toBitmap(iconPx, iconPx).asImageBitmap() }.getOrNull(),
            )
        }

        val groups = AppPresetCatalog.groups.map { group ->
            AppGroupUi(group.key, group.labelEn, group.labelZh, group.packages.mapNotNull(::appFor))
        }.filter { it.apps.isNotEmpty() }

        val presetPackages = AppPresetCatalog.allPackages
        val launcher = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        @Suppress("DEPRECATION")
        val launchable = pm.queryIntentActivities(launcher, PackageManager.MATCH_ALL)
            .map { it.activityInfo.packageName }
            .distinct()
            .filter { it != BuildConfig.APPLICATION_ID && it !in presetPackages }
        val others = launchable.mapNotNull(::appFor)
            .sortedWith(compareBy<InstalledApp> { isSystem(pm, it.packageName) }.thenBy { it.label.lowercase() })
        return groups to others
    }

    private fun isSystem(pm: PackageManager, packageName: String): Boolean =
        runCatching { pm.getApplicationInfo(packageName, 0).flags and ApplicationInfo.FLAG_SYSTEM != 0 }
            .getOrDefault(false)
}
