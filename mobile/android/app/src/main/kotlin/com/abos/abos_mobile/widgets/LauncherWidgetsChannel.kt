package com.abos.abos_mobile.widgets

import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodChannel

/**
 * ⭐ MethodChannel "abos/launcher_widgets" (widget kit, 20 Oct 2026).
 *
 *  - setEnabled({key: String, on: bool}) → null. Shows or hides that widget in
 *    the launcher's picker (all 21 start disabled in the manifest; Dart turns
 *    on only those the signed-in person may see). DONT_KILL_APP: the switch
 *    runs while the app is open.
 *  - refreshAll() → null. Redraws every placed instance of every kit widget.
 *  - keys() → List<String>, the 21 keys in kit order ([WidgetKeys.ALL]).
 *
 * An unknown key is an error ("UNKNOWN_KEY"), never a silent no-op: a key that
 * exists only in Dart would otherwise be a widget that never appears.
 */
object LauncherWidgetsChannel {
    const val NAME = "abos/launcher_widgets"

    fun register(messenger: BinaryMessenger, context: Context) {
        val app = context.applicationContext
        MethodChannel(messenger, NAME).setMethodCallHandler { call, result ->
            try {
                when (call.method) {
                    "setEnabled" -> {
                        val key = call.argument<String>("key")
                        val on = call.argument<Boolean>("on")
                        val cls = key?.let { WidgetKeys.RECEIVERS[it] }
                        if (cls == null || on == null) {
                            result.error("UNKNOWN_KEY", "unknown widget key or missing on: $key", null)
                        } else {
                            app.packageManager.setComponentEnabledSetting(
                                ComponentName(app, cls),
                                if (on) PackageManager.COMPONENT_ENABLED_STATE_ENABLED
                                else PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
                                PackageManager.DONT_KILL_APP,
                            )
                            result.success(null)
                        }
                    }

                    "refreshAll" -> {
                        LockWatch.refreshAll(app)
                        result.success(null)
                    }

                    "keys" -> result.success(WidgetKeys.ALL)

                    else -> result.notImplemented()
                }
            } catch (t: Throwable) {
                result.error("WIDGETS", t.message, null)
            }
        }
    }
}
