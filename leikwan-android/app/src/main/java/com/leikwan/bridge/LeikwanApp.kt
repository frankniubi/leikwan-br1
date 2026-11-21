package com.leikwan.bridge

import android.app.Application
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import com.leikwan.bridge.net.PanelNetwork

class LeikwanApp : Application() {
    override fun onCreate() {
        super.onCreate()
        com.leikwan.bridge.diagnostics.CrashJournal.install(this)
        val prefs = getSharedPreferences("locale-prefs", MODE_PRIVATE)
        if (!prefs.getBoolean("default_locale_applied", false)) {
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags("zh-CN"))
            prefs.edit().putBoolean("default_locale_applied", true).apply()
        }
        // Start tracking the physical network before the first sign-in; never fails startup.
        PanelNetwork.ensureInstalled(this)
        // Re-establish strict panel/DNS routing before enrollment screens or catalog requests.
        runCatching {
            val appPrefs = com.leikwan.bridge.data.Prefs.get(this)
            if (appPrefs.connectionMode == com.leikwan.bridge.service.AppConnectionMode.PORTS) {
                com.leikwan.bridge.net.DirectPortNetwork.start(this, appPrefs.portNetworkChoice)
            }
        }
    }
}
