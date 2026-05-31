package com.leikwan.bridge.data.routing

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

@Dao
interface UserRoutingRuleDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(entity: UserRoutingRuleEntity)

    @Query("SELECT * FROM user_routing_rules ORDER BY created_at ASC")
    suspend fun all(): List<UserRoutingRuleEntity>

    @Query("SELECT * FROM user_routing_rules WHERE kind = :kind ORDER BY created_at ASC")
    suspend fun byKind(kind: String): List<UserRoutingRuleEntity>

    @Query("UPDATE user_routing_rules SET enabled = :enabled WHERE id = :id")
    suspend fun setEnabled(id: String, enabled: Boolean)

    @Query("UPDATE user_routing_rules SET target_node_id = :targetNodeId WHERE id = :id")
    suspend fun setTargetNodeId(id: String, targetNodeId: Long?)

    @Query("DELETE FROM user_routing_rules WHERE id = :id")
    suspend fun deleteById(id: String)

    @Query("DELETE FROM user_routing_rules")
    suspend fun clear()
}
