package com.abos.abos_mobile

import android.Manifest
import android.app.ActivityManager
import android.app.ApplicationExitInfo
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.os.StatFs
import android.provider.Settings
import android.view.WindowManager
import com.abos.abos_mobile.widgets.LauncherWidgetsChannel
import com.abos.abos_mobile.widgets.LockWatch
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.security.MessageDigest

/**
 * Three questions Dart cannot answer for itself, all for the in-app update
 * (docs/Contract section 6, rule kha). See lib/core/update/apk_installer.dart
 * and storage_check.dart for the other end of each.
 */
// ⓘ FragmentActivity — the app lock's fingerprint/PIN prompt (local_auth) needs it (7 Oct 2026)
class MainActivity : FlutterFragmentActivity() {

    /** SEND_SMS চাওয়ার উত্তরের অপেক্ষায় থাকা ডাক — Android উত্তর দেয় [onRequestPermissionsResult]-এ */
    private var smsPermissionResult: MethodChannel.Result? = null

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == SMS_PERMISSION_REQUEST) {
            smsPermissionResult?.success(grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)
            smsPermissionResult = null
        }
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // ⭐ অফিসের SMS ফোন (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২) — চালু/বন্ধ, অবস্থা, আর SMS পাঠানোর অনুমতি।
        // ⓘ কাজটা সামনের-সেবায় (sms/SmsGatewayService) — অ্যাপ বন্ধ থাকলেও চলে; Dart শুধু সাজায় আর দেখায়
        // (lib/core/sms_gateway/sms_gateway_channel.dart)।
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, SMS_CHANNEL)
            .setMethodCallHandler { call, result ->
                val store = com.abos.abos_mobile.sms.SmsGatewayStore(this)
                when (call.method) {
                    "status" -> result.success(
                        mapOf(
                            "enabled" to store.enabled,
                            "baseUrl" to store.baseUrl,
                            "hasKey" to store.key.isNotEmpty(),
                            "lastRun" to store.lastRun,
                            "lastError" to store.lastError,
                            "sentToday" to store.sentToday(),
                            "waitingReports" to store.events().length(),
                            "permission" to (checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED),
                        )
                    )
                    "start" -> {
                        val base = call.argument<String>("baseUrl").orEmpty()
                        val key = call.argument<String>("key").orEmpty()
                        if (base.isNotEmpty()) store.baseUrl = base
                        if (key.isNotEmpty()) store.key = key
                        store.appVersion = call.argument<String>("appVersion").orEmpty()
                        if (store.baseUrl.isEmpty() || store.key.isEmpty()) {
                            result.error("MISSING", "address or key missing", null)
                        } else {
                            store.lastError = ""
                            store.enabled = true
                            com.abos.abos_mobile.sms.SmsGatewayService.start(this)
                            result.success(true)
                        }
                    }
                    "stop" -> {
                        store.enabled = false
                        com.abos.abos_mobile.sms.SmsGatewayService.stop(this)
                        result.success(true)
                    }
                    "requestPermission" -> {
                        if (checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED) {
                            result.success(true)
                        } else {
                            smsPermissionResult?.success(false)
                            smsPermissionResult = result
                            requestPermissions(arrayOf(Manifest.permission.SEND_SMS), SMS_PERMISSION_REQUEST)
                        }
                    }
                    else -> result.notImplemented()
                }
            }

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

                    // ⛔ The downloaded file, checked against this app before the
                    // installer sees it (20 Oct 2026): same package, same signing
                    // key, a higher versionCode. The SHA-256 already says it is the
                    // file the server named; this says the server named the right
                    // file. Without it a debug-signed or older APK put on the
                    // server reached Android's installer and ended as a bare
                    // "App not installed".
                    "checkArchive" -> {
                        val path = call.argument<String>("path")
                        if (path == null) {
                            result.error("ARGUMENT", "path missing", null)
                        } else {
                            try {
                                result.success(checkArchive(path))
                            } catch (error: Exception) {
                                result.error("ARCHIVE", error.message, null)
                            }
                        }
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

        // ⭐ Whether a runtime permission is already granted, so the app shows its
        // Bengali reason only when Android is about to ask (lib/core/permissions/
        // permission_rationale.dart, 20 Oct 2026). Notifications need no grant
        // before Android 13, so they read as granted there.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, PERMISSIONS_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "granted" -> {
                        val permission = when (call.argument<String>("name")) {
                            "camera" -> Manifest.permission.CAMERA
                            "notifications" ->
                                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                                    Manifest.permission.POST_NOTIFICATIONS
                                } else {
                                    null
                                }
                            else -> ""
                        }
                        when (permission) {
                            null -> result.success(true)
                            "" -> result.error("UNKNOWN", "unknown permission", null)
                            else -> result.success(
                                checkSelfPermission(permission) == PackageManager.PERMISSION_GRANTED
                            )
                        }
                    }

                    else -> result.notImplemented()
                }
            }

        // ⭐ Battery Saver — the live poll asks less often while it is on
        // (a6, 19 Oct 2026; lib/core/sync_engine/live_sync.dart, PowerSave).
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, POWER_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "isPowerSaveMode" -> result.success(
                        (getSystemService(POWER_SERVICE) as? PowerManager)?.isPowerSaveMode ?: false
                    )

                    else -> result.notImplemented()
                }
            }

        // ⭐ What the crash report says about the phone (ec, app enterprise
        // grade, 19 Oct 2026): the Android version and the maker and model,
        // nothing that names the person. lib/core/crash/crash_reporter.dart.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, DEVICE_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "describe" -> result.success(
                        mapOf(
                            "android" to "${Build.VERSION.RELEASE} (SDK ${Build.VERSION.SDK_INT})",
                            "device" to "${Build.MANUFACTURER} ${Build.MODEL}",
                        )
                    )

                    // ⭐ Why earlier runs of this app ended — only ANRs and
                    // native crashes, the two Dart cannot see (ec, 19 Oct
                    // 2026). Android keeps the record itself from 11 (API 30);
                    // older phones answer an empty list. The time is the
                    // dedupe mark on the Dart side; no trace file is read.
                    "exits" -> {
                        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
                            result.success(emptyList<Map<String, Any>>())
                        } else {
                            try {
                                val manager = getSystemService(ACTIVITY_SERVICE) as ActivityManager
                                val exits = manager.getHistoricalProcessExitReasons(packageName, 0, 10)
                                    .filter {
                                        it.reason == ApplicationExitInfo.REASON_ANR ||
                                            it.reason == ApplicationExitInfo.REASON_CRASH_NATIVE
                                    }
                                    .map {
                                        mapOf(
                                            "reason" to if (it.reason == ApplicationExitInfo.REASON_ANR) "anr" else "native",
                                            "time" to it.timestamp,
                                            "description" to (it.description ?: ""),
                                        )
                                    }
                                result.success(exits)
                            } catch (error: Exception) {
                                result.success(emptyList<Map<String, Any>>())
                            }
                        }
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

        // ⭐ root করা বা ছদ্ম-ফোন — সতর্ক করার জন্য, আটকানোর জন্য নয় (app security, OWASP MASVS-RESILIENCE-1,
        // 19 Oct 2026). lib/core/privacy/device_integrity.dart shows the warning once a run.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, INTEGRITY_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "check" -> result.success(mapOf("rooted" to isRooted(), "emulator" to isEmulator()))
                    else -> result.notImplemented()
                }
            }

        // ⭐ Home-screen widget kit (20 Oct 2026): which widgets the picker offers,
        // redraw all, and the key list (widgets/LauncherWidgetsChannel.kt). While
        // the app runs, the kit's secret texts follow lock and unlock (LockWatch).
        LauncherWidgetsChannel.register(flutterEngine.dartExecutor.binaryMessenger, applicationContext)
        LockWatch.ensure(applicationContext)
    }

    /**
     * Package name, signer and versionCode of an APK on disk against this
     * installed app. A signer that cannot be read on either side is null,
     * "unknown", and the installer stays the judge of it.
     */
    private fun checkArchive(path: String): Map<String, Any?> {
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            PackageManager.GET_SIGNING_CERTIFICATES
        } else {
            @Suppress("DEPRECATION")
            PackageManager.GET_SIGNATURES
        }
        val archive = packageManager.getPackageArchiveInfo(path, flags)
            ?: return mapOf("readable" to false)
        val self = packageManager.getPackageInfo(packageName, flags)
        val archiveSigners = signerDigests(archive)
        val selfSigners = signerDigests(self)
        return mapOf(
            "readable" to true,
            "samePackage" to (archive.packageName == packageName),
            "sameSigner" to if (archiveSigners.isEmpty() || selfSigners.isEmpty()) null else archiveSigners == selfSigners,
            "newer" to (longVersionCode(archive) > longVersionCode(self)),
        )
    }

    private fun signerDigests(info: PackageInfo): Set<String> {
        val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            info.signingInfo?.apkContentsSigners
        } else {
            @Suppress("DEPRECATION")
            info.signatures
        }
        return signatures.orEmpty().map { signature ->
            MessageDigest.getInstance("SHA-256").digest(signature.toByteArray())
                .joinToString("") { "%02x".format(it) }
        }.toSet()
    }

    private fun longVersionCode(info: PackageInfo): Long =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            info.longVersionCode
        } else {
            @Suppress("DEPRECATION")
            info.versionCode.toLong()
        }

    /** su-এর ফাইল, test-keys-এ সই করা বিল্ড, বা Magisk-এর চিহ্ন — কোনো একটা থাকলেই */
    private fun isRooted(): Boolean {
        if (Build.TAGS?.contains("test-keys") == true) return true
        val paths = listOf(
            "/system/bin/su", "/system/xbin/su", "/sbin/su", "/system/su", "/system/bin/.ext/su",
            "/system/usr/we-need-root/su", "/data/local/su", "/data/local/bin/su", "/data/local/xbin/su",
            "/su/bin/su", "/system/app/Superuser.apk", "/sbin/.magisk", "/data/adb/magisk",
        )
        return paths.any { runCatching { java.io.File(it).exists() }.getOrDefault(false) }
    }

    /** Android-এর নিজের emulator বা পরিচিত ছদ্ম-ফোনের চিহ্ন */
    private fun isEmulator(): Boolean =
        Build.FINGERPRINT.startsWith("generic") || Build.FINGERPRINT.startsWith("unknown") ||
            Build.FINGERPRINT.contains("emulator") ||
            Build.MODEL.contains("google_sdk") || Build.MODEL.contains("Emulator") ||
            Build.MODEL.contains("Android SDK built for") ||
            Build.MANUFACTURER.contains("Genymotion") ||
            Build.HARDWARE.contains("goldfish") || Build.HARDWARE.contains("ranchu") ||
            Build.PRODUCT.contains("sdk") || Build.PRODUCT.contains("emulator") ||
            (Build.BRAND.startsWith("generic") && Build.DEVICE.startsWith("generic"))

    companion object {
        private const val DEVICE_CHANNEL = "com.abos.abos_mobile/device"
        private const val SECURE_CHANNEL = "com.abos.abos_mobile/secure"
        private const val INTEGRITY_CHANNEL = "com.abos.abos_mobile/integrity"
        private const val INSTALL_CHANNEL = "com.abos.abos_mobile/install"
        private const val STORAGE_CHANNEL = "com.abos.abos_mobile/storage"
        private const val PERMISSIONS_CHANNEL = "com.abos.abos_mobile/permissions"
        private const val POWER_CHANNEL = "abos/power"
        private const val SMS_CHANNEL = "com.abos.abos_mobile/sms_gateway"
        private const val SMS_PERMISSION_REQUEST = 7302
    }
}
