package com.leikwan.bridge.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(entities = [ProxyNode::class, Subscription::class], version = 1)
abstract class AppDatabase : RoomDatabase() {
    abstract fun proxyNodeDao(): ProxyNodeDao
    abstract fun subscriptionDao(): SubscriptionDao

    companion object {
        @Volatile private var INSTANCE: AppDatabase? = null

        fun get(context: Context): AppDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "leikwan_bridge.db"
                ).build().also { INSTANCE = it }
            }
    }
}
