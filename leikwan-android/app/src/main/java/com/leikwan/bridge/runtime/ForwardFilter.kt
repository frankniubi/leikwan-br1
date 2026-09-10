package com.leikwan.bridge.runtime

object ForwardFilter {
    fun buildCatalog(
        contracts: List<ForwardContract>,
        selectedForwardId: Long?,
    ): ForwardCatalog {
        val visible = mutableListOf<ForwardListItem>()
        for (contract in contracts) {
            if (contract.routeRuleHelperOnly) {
                continue
            }
            val effective = AndroidEffectiveForwardBuilder.build(contract)
            if (effective != null) {
                visible += effective.toListItem()
            }
        }

        val finalSelected = selectedForwardId?.takeIf { desired ->
            visible.any { it.forwardId == desired }
        }

        return ForwardCatalog(
            visibleForwards = visible,
            selectedForwardId = finalSelected,
        )
    }
}
