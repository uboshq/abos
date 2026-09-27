package com.abos.abos_mobile

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.StatFs
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Three questions Dart cannot answer for itself, all for the in-app update
 * (docs/Contract section 6, rule kha). See lib/core/update/apk_installer.dart
 * and storage_check.dart for the other end of each.
 */
class MainActivity : FlutterActivity() {

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, INSTALL_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    // Before Android 8 the permission in the manifest was
                    // enough. From 8 it only buys the right to ask, and the
                    // person has to switch it on for this app.
                    "canInstallApks" -> result.success(
                        Build.VERSION.SDK_INT < Build.VERSION_CODES.O ||
                            packageManager.canRequestPackageInstalls()
                    )

                    // Straight to this app's own entry, not the top of
                    // Settings: a rep should not have to find themselves in a
                    // list of every app on the phone.
                    "openInstallSettings" -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            startActivity(
                                Intent(
                                    Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                                    Uri.parse("package:$packageName")
                                )
                            )
                        }
                        result.success(null)
                    }

                    else -> result.notImplemented()
                }
            }

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, STORAGE_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    // Where the download lands: the app's own cache
                    // directory. Asked before the download starts, not
                    // discovered when it fails part way with the phone full.
                    "freeBytes" -> try {
                        result.success(StatFs(cacheDir.path).availableBytes)
                    } catch (error: Exception) {
                        result.error("STATFS", error.message, null)
                    }

                    else -> result.notImplemented()
                }
            }
    }

    companion object {
        private const val INSTALL_CHANNEL = "com.abos.abos_mobile/install"
        private const val STORAGE_CHANNEL = "com.abos.abos_mobile/storage"
    }
}
