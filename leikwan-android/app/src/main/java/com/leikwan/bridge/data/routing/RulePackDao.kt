package com.leikwan.bridge.data.routing

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

@Dao
interface RulePackDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(entity: RulePackSnapshotEntity)

    @Query("SELECT * FROM rule_pack_snapshots WHERE slot = 'active' LIMIT 1")
    suspend fun active(): RulePackSnapshotEntity?

    @Query("SELECT * FROM rule_pack_snapshots WHERE slot = 'previous' LIMIT 1")
    suspend fun previous(): RulePackSnapshotEntity?

    @Query("DELETE FROM rule_pack_snapshots WHERE slot = :slot")
    suspend fun deleteBySlot(slot: String)
}
