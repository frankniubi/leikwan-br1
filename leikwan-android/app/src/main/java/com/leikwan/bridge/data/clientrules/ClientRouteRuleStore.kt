package com.leikwan.bridge.data.clientrules

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.Gson
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.ClientRuleValidationResult
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import com.leikwan.bridge.clientrules.toDomain
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.util.UUID

class ClientRouteRuleStore(
    private val prefs: SharedPreferences,
    private val gson: Gson = Gson(),
    private val clock: () -> Long = System::currentTimeMillis,
) {
    private val _snapshot = MutableStateFlow(loadSnapshot())
    val snapshot: StateFlow<ClientRouteRuleSnapshot> = _snapshot.asStateFlow()

    fun current(): ClientRouteRuleSnapshot = _snapshot.value

    fun replaceFromPull(
        routeDirections: List<RouteDirectionDto>,
        entitlements: List<RouteRuleEntitlementDto>,
        rules: List<ClientRouteRuleDto>,
        bindings: List<DirectionRuntimeBindingDto>,
        capabilities: RouteRuleCapabilitiesDto?,
    ) {
        val now = clock()
        val previousRules = current().rules
        val pending = previousRules.filter { it.pendingOperation != PendingClientRuleOperation.NONE }
        val pendingById = pending.associateBy { it.id }
        val serverRules = rules.map { it.toDomain(now) }
        val serverRuleIds = serverRules.mapTo(mutableSetOf()) { it.id }
        val mergedServerRules = serverRules.map { server -> pendingById[server.id] ?: server }
        val pendingLocalOnly = pending.filter { local ->
            local.pendingOperation == PendingClientRuleOperation.CREATE || local.id !in serverRuleIds
        }
        save(
            ClientRouteRuleSnapshot(
                directions = routeDirections.map { it.toDomain() }.sortedBy { it.sortOrder },
                entitlements = entitlements.map { it.toDomain() },
                rules = (mergedServerRules + pendingLocalOnly).distinctBy { it.id },
                bindings = bindings.map { it.toDomain() },
                capabilities = capabilities.toDomain(),
                updatedAtMs = now,
            )
        )
    }

    fun activeRulesForScope(serviceScopeId: String): List<ClientRouteRule> = current().rules
        .filter { it.serviceScopeId == serviceScopeId && it.deletedAtMs == null && it.pendingOperation != PendingClientRuleOperation.DELETE }
        .sortedWith(compareBy<ClientRouteRule> { it.destinationIp }.thenBy { it.destinationPort })

    fun markPendingCreate(draft: ClientRuleWriteDraft): ClientRouteRule {
        validateDraft(draft).error?.let { throw IllegalArgumentException(it) }
        val now = clock()
        val rule = ClientRouteRule(
            id = "local_${UUID.randomUUID().toString().replace("-", "")}",
            serviceScopeId = draft.serviceScopeId.orEmpty(),
            destinationIp = draft.destinationIp.orEmpty().trim(),
            destinationPort = draft.destinationPort ?: 0,
            protocol = "tcp_udp",
            directionId = draft.directionId.orEmpty(),
            enabled = draft.enabled ?: true,
            effectiveState = ClientRuleEffectiveState.SYNCING,
            syncVersion = 0L,
            pendingOperation = PendingClientRuleOperation.CREATE,
            updatedAtMs = now,
        )
        save(current().copy(rules = current().rules + rule, updatedAtMs = now))
        return rule
    }

    fun markPendingUpdate(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule {
        val now = clock()
        val existing = current().rules.first { it.id == ruleId }
        val updated = existing.copy(
            destinationIp = draft.destinationIp?.trim() ?: existing.destinationIp,
            destinationPort = draft.destinationPort ?: existing.destinationPort,
            directionId = draft.directionId ?: existing.directionId,
            enabled = draft.enabled ?: existing.enabled,
            effectiveState = ClientRuleEffectiveState.SYNCING,
            pendingOperation = if (existing.pendingOperation == PendingClientRuleOperation.CREATE) {
                PendingClientRuleOperation.CREATE
            } else {
                PendingClientRuleOperation.UPDATE
            },
            pendingError = null,
            deletedAtMs = null,
            updatedAtMs = now,
        )
        validateDraft(
            ClientRuleWriteDraft(
                serviceScopeId = updated.serviceScopeId,
                destinationIp = updated.destinationIp,
                destinationPort = updated.destinationPort,
                directionId = updated.directionId,
                enabled = updated.enabled,
                syncVersion = updated.syncVersion,
            )
        ).error?.let { throw IllegalArgumentException(it) }
        save(current().copy(rules = current().rules.map { if (it.id == ruleId) updated else it }, updatedAtMs = now))
        return updated
    }

    fun markPendingTombstone(ruleId: String): ClientRouteRule {
        val now = clock()
        val updated = current().rules.first { it.id == ruleId }.copy(
            effectiveState = ClientRuleEffectiveState.SYNCING,
            pendingOperation = PendingClientRuleOperation.DELETE,
            pendingError = null,
            deletedAtMs = now,
            updatedAtMs = now,
        )
        save(current().copy(rules = current().rules.map { if (it.id == ruleId) updated else it }, updatedAtMs = now))
        return updated
    }

    fun applyServerRule(dto: ClientRouteRuleDto, localId: String? = null): ClientRouteRule {
        val now = clock()
        val server = dto.toDomain(now)
        val kept = current().rules.filterNot { it.id == server.id || (localId != null && it.id == localId) }
        save(current().copy(rules = kept + server, updatedAtMs = now))
        return server
    }

    fun markPendingError(ruleId: String, error: String) {
        val now = clock()
        val updated = current().rules.map { rule ->
            if (rule.id == ruleId) {
                rule.copy(
                    effectiveState = ClientRuleEffectiveState.ERROR,
                    pendingError = error,
                    updatedAtMs = now,
                )
            } else {
                rule
            }
        }
        save(current().copy(rules = updated, updatedAtMs = now))
    }

    fun clear() = save(ClientRouteRuleSnapshot())

    private fun loadSnapshot(): ClientRouteRuleSnapshot = runCatching {
        prefs.getString(KEY_SNAPSHOT, null)?.let { encoded ->
            gson.fromJson(encoded, ClientRouteRuleSnapshot::class.java)
        }
    }.getOrNull() ?: ClientRouteRuleSnapshot()

    private fun save(snapshot: ClientRouteRuleSnapshot) {
        prefs.edit().putString(KEY_SNAPSHOT, gson.toJson(snapshot)).apply()
        _snapshot.value = snapshot
    }

    companion object {
        private const val KEY_SNAPSHOT = "snapshot"
        private val IPV4 = Regex("^(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}$")

        fun from(context: Context): ClientRouteRuleStore = ClientRouteRuleStore(
            prefs = context.applicationContext.getSharedPreferences("client-route-rules", Context.MODE_PRIVATE),
        )

        fun validateDraft(draft: ClientRuleWriteDraft): ClientRuleValidationResult {
            val ip = draft.destinationIp?.trim().orEmpty()
            val port = draft.destinationPort ?: 0
            return when {
                ip.isBlank() || !IPV4.matches(ip) -> ClientRuleValidationResult("invalid_destination_ip")
                port !in 1..65535 -> ClientRuleValidationResult("invalid_destination_port")
                draft.directionId?.trim().isNullOrEmpty() -> ClientRuleValidationResult("direction_not_allowed")
                else -> ClientRuleValidationResult()
            }
        }
    }
}
