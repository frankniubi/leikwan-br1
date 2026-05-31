package com.leikwan.bridge.ui.routing

import android.app.Application
import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.UserTrafficOutcome
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import java.util.concurrent.Executor
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Unit-tests [RoutingViewModel] WITHOUT EncryptedSharedPreferences: an in-memory Room-backed store
 * and a plain-SharedPreferences-backed [Prefs] (via the internal constructor) are injected.
 */
@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RoutingViewModelTest {

    private val dispatcher = StandardTestDispatcher()
    private lateinit var db: AppDatabase
    private lateinit var store: UserRoutingRuleStore
    private lateinit var prefs: Prefs
    private lateinit var app: Application

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        val context = ApplicationProvider.getApplicationContext<Context>()
        app = context as Application
        // Run Room's suspend DAO queries inline (caller thread) so they complete within the test
        // scheduler's advanceUntilIdle, instead of on Room's own background executor.
        val inlineExecutor = Executor { it.run() }
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .setQueryExecutor(inlineExecutor)
            .setTransactionExecutor(inlineExecutor)
            .build()
        store = UserRoutingRuleStore(db)
        prefs = Prefs(context.getSharedPreferences("routing-vm-test", Context.MODE_PRIVATE))
    }

    @After
    fun tearDown() {
        db.close()
        Dispatchers.resetMain()
    }

    private fun newViewModel(): RoutingViewModel =
        RoutingViewModel(
            app = app,
            db = db,
            store = store,
            prefs = prefs,
            categoryCatalog = RoutingCategoryCatalog.default(),
        )

    @Test
    fun `preset categories come from the catalog`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()
        assertEquals(listOf("video", "ai"), vm.state.value.presetCategories)
    }

    @Test
    fun `addCountryRule persists and surfaces in state`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.addCountryRule("cn", UserTrafficOutcome.DIRECT)
        advanceUntilIdle()

        val countryRules = vm.state.value.countryRules
        assertEquals(1, countryRules.size)
        assertEquals("CN", countryRules.single().matchValue)
        assertEquals(UserTrafficOutcome.DIRECT, countryRules.single().outcome)

        val stored = store.countryRules()
        assertEquals(1, stored.size)
        assertEquals("CN", stored.single().matchValue)
        assertEquals(UserTrafficOutcome.DIRECT, stored.single().outcome)
    }

    @Test
    fun `addCategoryRule rejects unknown category but accepts presets`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.addCategoryRule("not-a-category", UserTrafficOutcome.TUNNEL)
        advanceUntilIdle()
        assertTrue(vm.state.value.categoryRules.isEmpty())

        vm.addCategoryRule("video", UserTrafficOutcome.TUNNEL)
        advanceUntilIdle()
        assertEquals(1, vm.state.value.categoryRules.size)
        assertEquals("video", vm.state.value.categoryRules.single().matchValue)
        assertEquals(UserTrafficOutcome.TUNNEL, vm.state.value.categoryRules.single().outcome)
    }

    @Test
    fun `setGlobalDefault writes prefs and state`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.setGlobalDefault(UserTrafficOutcome.DIRECT)
        advanceUntilIdle()

        assertEquals(UserTrafficOutcome.DIRECT, vm.state.value.globalDefault)
        assertEquals(UserTrafficOutcome.DIRECT, prefs.routingDefaultOutcome)
    }

    @Test
    fun `node targets persist and available nodes exclude disabled nodes`() = runTest(dispatcher) {
        val nodeA = db.proxyNodeDao().insert(
            ProxyNode(
                name = "Tokyo",
                type = "ss",
                originalServer = "tokyo.example.com",
                originalPort = 443,
                configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                enabled = true,
            )
        )
        db.proxyNodeDao().insert(
            ProxyNode(
                name = "Disabled",
                type = "ss",
                originalServer = "disabled.example.com",
                originalPort = 443,
                configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                enabled = false,
            )
        )
        val vm = newViewModel()
        advanceUntilIdle()

        assertEquals(listOf(nodeA), vm.state.value.availableNodes.map { it.id })

        vm.addCategoryRule("video", UserTrafficOutcome.TUNNEL)
        advanceUntilIdle()
        vm.setRuleTargetNode("category-video", nodeA)
        advanceUntilIdle()
        vm.setGlobalDefaultNode(nodeA)
        advanceUntilIdle()

        assertEquals(nodeA, vm.state.value.globalDefaultNodeId)
        assertEquals(nodeA, prefs.routingDefaultNodeId)
        assertEquals(nodeA, vm.state.value.categoryRules.single().targetNodeId)
        assertEquals(nodeA, store.categoryRules().single().targetNodeId)
    }

    @Test
    fun `toggleRule and deleteRule are reflected`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.addCountryRule("JP", UserTrafficOutcome.TUNNEL)
        advanceUntilIdle()
        val id = vm.state.value.countryRules.single().id
        assertTrue(vm.state.value.countryRules.single().enabled)

        vm.toggleRule(id)
        advanceUntilIdle()
        assertFalse(vm.state.value.countryRules.single().enabled)

        vm.deleteRule(id)
        advanceUntilIdle()
        assertTrue(vm.state.value.countryRules.isEmpty())
    }
}
