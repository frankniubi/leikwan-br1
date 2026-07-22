package com.leikwan.bridge.runtime

object ForwardFilter {
    fun buildCatalog(
        contracts: List<ForwardContract>,
        selectedForwardId: Long?,
    ): ForwardCatalog {
        val visible = mutableListOf<ForwardListItem>()
        val hiddenIds = mutableSetOf<Long>()

        for (contract in contracts) {
            val effective = AndroidEffectiveForwardBuilder.build(contract)
            if (effective == null) {
                hiddenIds += contract.forwardId
            } else {
                visible += effective.toListItem()
            }
        }

        val finalSelected = selectedForwardId?.takeIf { desired ->
            visible.any { it.forwardId == desired }
        }

        return ForwardCatalog(
            visibleForwards = visible,
            hiddenUnsupportedMptcpCount = 0,
            hiddenForwardIds = hiddenIds,
            selectedForwardId = finalSelected,
        )
    }
}
