package com.leikwan.bridge.data

import androidx.room.Dao
import androidx.room.Delete
import androidx.room.Insert
import androidx.room.Query
import androidx.room.Update
import kotlinx.coroutines.flow.Flow

@Dao
interface ProxyNodeDao {
    @Query("SELECT * FROM proxy_nodes ORDER BY createdAt DESC")
    fun getAll(): Flow<List<ProxyNode>>

    @Query("SELECT * FROM proxy_nodes WHERE enabled = 1 ORDER BY createdAt DESC")
    suspend fun getEnabled(): List<ProxyNode>

    @Query("SELECT * FROM proxy_nodes ORDER BY createdAt DESC")
    suspend fun getAllList(): List<ProxyNode>

    @Query("SELECT * FROM proxy_nodes WHERE id = :id LIMIT 1")
    suspend fun getById(id: Long): ProxyNode?

    @Query("SELECT * FROM proxy_nodes WHERE hostedId IS NOT NULL")
    suspend fun getHosted(): List<ProxyNode>

    @Query("SELECT * FROM proxy_nodes WHERE subscriptionId = :subId")
    suspend fun getBySubscription(subId: Long): List<ProxyNode>

    @Insert
    suspend fun insert(node: ProxyNode): Long

    @Insert
    suspend fun insertAll(nodes: List<ProxyNode>)

    @Update
    suspend fun update(node: ProxyNode)

    @Delete
    suspend fun delete(node: ProxyNode)

    @Query("DELETE FROM proxy_nodes WHERE subscriptionId = :subId")
    suspend fun deleteBySubscription(subId: Long)
}
