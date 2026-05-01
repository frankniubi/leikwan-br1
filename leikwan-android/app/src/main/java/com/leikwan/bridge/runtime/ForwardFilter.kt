package com.leikwan.bridge.runtime

object ForwardFilter {
    fun buildCatalog(
        contracts: List<ForwardContract>,
        selectedForwardId: Long?,
    ): ForwardCatalog {
        val hiddenIds = mutableSetOf<Long>()
        var hiddenMptcpCount = 0
        val visible = mutableListOf<ForwardListItem>()

        for (contract in contracts) {
            when (contract.mode) {
                ForwardMode.MPTCP -> {
                    hiddenIds += contract.forwardId
                    hiddenMptcpCount += 1
                }
                ForwardMode.SINGLE, ForwardMode.BOND -> visible += contract.toListItem()
                ForwardMode.RACE -> hiddenIds += contract.forwardId
            }
        }

        val finalSelected = selectedForwardId?.takeIf { desired ->
            visible.any { it.forwardId == desired }
        }

        return ForwardCatalog(
            visibleForwards = visible,
            hiddenUnsupportedMptcpCount = hiddenMptcpCount,
            hiddenForwardIds = hiddenIds,
            selectedForwardId = finalSelected,
        )
    }
}
