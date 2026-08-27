package com.leikwan.bridge.runtime

import android.system.Os
import android.system.OsConstants
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class RuntimeBinaryInstallerPermissionsTest {
    @Test
    fun extractedRuntimeBinaryIsOwnerOnly() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val assetContext = instrumentation.context
        val target = File(instrumentation.targetContext.cacheDir, "runtime-permission-test").apply { delete() }

        try {
            val installMethod = RuntimeBinaryInstaller::class.java.getDeclaredMethod(
                "installAssetBinary",
                String::class.java,
                File::class.java,
            ).apply { isAccessible = true }

            val installedPath = installMethod.invoke(
                RuntimeBinaryInstaller(assetContext),
                "runtime-permission-test.bin",
                target,
            )
            val permissionMask = OsConstants.S_IRWXU or OsConstants.S_IRWXG or OsConstants.S_IRWXO
            val expectedOwnerPermissions = OsConstants.S_IRUSR or OsConstants.S_IWUSR or OsConstants.S_IXUSR

            assertEquals(target.absolutePath, installedPath)
            assertTrue(target.readText().startsWith("runtime-permission-test"))
            assertEquals(expectedOwnerPermissions, Os.stat(target.absolutePath).st_mode and permissionMask)
        } finally {
            target.delete()
        }
    }
}
