package com.leikwan.bridge.data

import androidx.room.Dao
import androidx.room.Delete
import androidx.room.Insert
import androidx.room.Query
import androidx.room.Update
import kotlinx.coroutines.flow.Flow

@Dao
interface SubscriptionDao {
    @Query("SELECT * FROM subscriptions ORDER BY name")
    fun getAll(): Flow<List<Subscription>>

    @Query("SELECT * FROM subscriptions WHERE id = :id")
    suspend fun getById(id: Long): Subscription?

    @Insert
    suspend fun insert(sub: Subscription): Long

    @Update
    suspend fun update(sub: Subscription)

    @Delete
    suspend fun delete(sub: Subscription)
}
