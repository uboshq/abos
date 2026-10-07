package com.abos.abos_mobile

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.StatFs
import android.provider.Settings
import android.view.WindowManager
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Three questions Dart cannot answer for itself, all for the in-app update
 * (docs/Contract section 6, rule kha). See lib/core/update/apk_installer.dart
 * and storage_check.dart for the other end of each.
 */
// ⓘ FragmentActivity — the app lock's fingerprint/PIN prompt (local_auth) needs it (7 Oct 2026)
class MainActivity : FlutterFragmentActivity() {

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

        // ⭐ Screens out of screenshots and the recent-apps preview — the
        // owner's switch, mobile.secure_screens (coordinator's app audit,
        // 7 Oct 2026). lib/core/privacy/phone_privacy.dart turns it on at
        // start and follows /me after.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, SECURE_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "set" -> {
                        val on = call.argument<Boolean>("on") ?: true
                        runOnUiThread {
                            if (on) {
                                window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
                            } else {
                                window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                            }
                            result.success(null)
                        }
                    }

                    else -> result.notImplemented()
                }
            }
    }

    companion object {
        private const val SECURE_CHANNEL = "com.abos.abos_mobile/secure"
        private const val INSTALL_CHANNEL = "com.abos.abos_mobile/install"
        private const val STORAGE_CHANNEL = "com.abos.abos_mobile/storage"
    }
}
