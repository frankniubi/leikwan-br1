package com.leikwan.bridge

import android.app.Application
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat

class LeikwanApp : Application() {
    override fun onCreate() {
        super.onCreate()
        val prefs = getSharedPreferences("locale-prefs", MODE_PRIVATE)
        if (!prefs.getBoolean("default_locale_applied", false)) {
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags("zh-CN"))
            prefs.edit().putBoolean("default_locale_applied", true).apply()
        }
    }
}
