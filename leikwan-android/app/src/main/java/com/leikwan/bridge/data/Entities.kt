package com.leikwan.bridge.data

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "proxy_nodes")
data class ProxyNode(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val name: String,
    val type: String,
    val originalServer: String,
    val originalPort: Int,
    val configJson: String,
    val subscriptionId: Long? = null,
    val forwardId: Long? = null,
    val enabled: Boolean = true,
    val createdAt: Long = System.currentTimeMillis(),
)

@Entity(tableName = "subscriptions")
data class Subscription(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val name: String,
    val url: String,
    val lastUpdated: Long = 0,
    val autoRefresh: Boolean = true,
    val refreshIntervalMin: Int = 1440,
)
