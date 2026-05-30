package com.leikwan.bridge.data.routing

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

@Dao
interface RoutingExplainabilityDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(entity: RoutingExplainabilityEntity)

    @Query("SELECT * FROM routing_explainability WHERE slot = 'latest' LIMIT 1")
    suspend fun latest(): RoutingExplainabilityEntity?
}
