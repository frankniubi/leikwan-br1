package com.leikwan.bridge.ui.tunnels

import android.app.Application
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.ForwardStrategyStore
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.runtime.ForwardStrategy
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File

@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class TunnelsViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var app: Application
    private lateinit var prefs: Prefs
    private lateinit var store: ForwardStrategyStore

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        app = ApplicationProvider.getApplicationContext()
        val shared = app.getSharedPreferences("tunnels-strategy-vm-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared).apply {
            panelUrl = "https://panel-a.example.test"
            clientId = 9
            selectedForwardId = 77
        }
        store = ForwardStrategyStore(
            shared,
            prefs.panelUrl,
            prefs.clientId,
            File(app.cacheDir, "tunnels-strategy-${System.nanoTime()}"),
        )
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `select strategy updates local state and store without refreshing panel`() {
        val viewModel = TunnelsViewModel(app, prefs, store)

        viewModel.selectStrategy(ForwardStrategy.RANDOM)

        assertEquals(ForwardStrategy.RANDOM, viewModel.state.value.selectedStrategy)
        assertEquals(ForwardStrategy.RANDOM, store.get(77))
    }
}
