package com.leikwan.bridge.update

import android.app.Application
import android.content.Intent
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.BuildConfig
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.io.File

sealed interface AppUpdateStatus {
    data object Idle : AppUpdateStatus
    data object Checking : AppUpdateStatus
    data object UpToDate : AppUpdateStatus
    data class Available(val manifest: AppUpdateManifest) : AppUpdateStatus
    data class Downloading(
        val manifest: AppUpdateManifest,
        val downloadedBytes: Long,
        val totalBytes: Long,
    ) : AppUpdateStatus
    data class ReadyToInstall(
        val manifest: AppUpdateManifest,
        val file: File,
    ) : AppUpdateStatus
    data class Error(val message: String) : AppUpdateStatus
}

data class AppUpdateUiState(
    val currentVersionName: String = BuildConfig.VERSION_NAME,
    val currentVersionCode: Long = BuildConfig.VERSION_CODE.toLong(),
    val status: AppUpdateStatus = AppUpdateStatus.Idle,
)

class AppUpdateViewModel @JvmOverloads constructor(
    app: Application,
    private val source: AppUpdateSource = AppUpdateService(app),
    private val installer: AppUpdateInstallerPort = AppUpdateInstaller(app),
) : AndroidViewModel(app) {
    private val _state = MutableStateFlow(AppUpdateUiState())
    val state = _state.asStateFlow()

    fun checkForUpdates(autoDownload: Boolean = false) {
        if (_state.value.status is AppUpdateStatus.Checking
            || _state.value.status is AppUpdateStatus.Downloading
        ) {
            return
        }
        viewModelScope.launch {
            _state.value = _state.value.copy(status = AppUpdateStatus.Checking)
            when (val result = source.checkForUpdate()) {
                is AppUpdateCheckResult.Available -> {
                    _state.value = _state.value.copy(status = AppUpdateStatus.Available(result.manifest))
                    if (autoDownload) downloadAndPrepare(result.manifest)
                }
                AppUpdateCheckResult.UpToDate -> {
                    _state.value = _state.value.copy(status = AppUpdateStatus.UpToDate)
                }
                is AppUpdateCheckResult.Failure -> {
                    _state.value = _state.value.copy(status = AppUpdateStatus.Error(result.message))
                }
            }
        }
    }

    fun downloadAndPrepare() {
        val manifest = (_state.value.status as? AppUpdateStatus.Available)?.manifest ?: return
        downloadAndPrepare(manifest)
    }

    private fun downloadAndPrepare(manifest: AppUpdateManifest) {
        if (_state.value.status is AppUpdateStatus.Downloading) return
        viewModelScope.launch {
            _state.value = _state.value.copy(
                status = AppUpdateStatus.Downloading(
                    manifest = manifest,
                    downloadedBytes = 0L,
                    totalBytes = manifest.sizeBytes,
                ),
            )
            when (val result = source.download(manifest) { downloaded, total ->
                _state.update { current ->
                    val downloading = current.status as? AppUpdateStatus.Downloading
                    if (downloading?.manifest?.versionCode == manifest.versionCode) {
                        current.copy(
                            status = downloading.copy(
                                downloadedBytes = downloaded,
                                totalBytes = total,
                            ),
                        )
                    } else {
                        current
                    }
                }
            }) {
                is AppUpdateDownloadResult.Success -> {
                    val intentResult = installer.createInstallIntent(result.file, manifest)
                    if (intentResult.isSuccess) {
                        _state.value = _state.value.copy(
                            status = AppUpdateStatus.ReadyToInstall(manifest, result.file),
                        )
                    } else {
                        _state.value = _state.value.copy(
                            status = AppUpdateStatus.Error(
                                intentResult.exceptionOrNull()?.message ?: "Update package is invalid",
                            ),
                        )
                    }
                }
                is AppUpdateDownloadResult.Failure -> {
                    _state.value = _state.value.copy(status = AppUpdateStatus.Error(result.message))
                }
            }
        }
    }

    fun installIntent(): Result<Intent> {
        val ready = _state.value.status as? AppUpdateStatus.ReadyToInstall
            ?: return Result.failure(IllegalStateException("update_not_ready"))
        return installer.createInstallIntent(ready.file, ready.manifest)
    }

    fun canRequestPackageInstalls(): Boolean = installer.canRequestPackageInstalls()

    fun unknownSourcesSettingsIntent(): Intent = installer.unknownSourcesSettingsIntent()

    fun reset() {
        _state.value = _state.value.copy(status = AppUpdateStatus.Idle)
    }
}
