package com.abos.abos_mobile.widgets

import android.app.KeyguardManager
import android.appwidget.AppWidgetManager
import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.os.PowerManager
import android.util.Log

/**
 * Secret texts (money) follow the phone's lock: "••••" while locked, the
 * figure after unlock.
 *
 * ⚠️ The honest limit. Android does not tell an app when one of its widgets is
 * being looked at, so hiding cannot follow the eye; it follows lock and unlock.
 * On ACTION_SCREEN_OFF and ACTION_USER_PRESENT every placed widget of the kit is
 * redrawn, and each draw asks the phone at that moment whether it is locked.
 *
 * ⚠️ Both broadcasts reach only a receiver registered at run time (Android 8+
 * delivers neither to a manifest receiver), so this listens only while the
 * app's process is alive: it is registered from MainActivity and from every
 * widget update. If the process has died, the next draw (the app's own refresh,
 * or a tap that opens the app) catches up: a widget drawn while locked may stay
 * "••••" after unlock until then, and that is the safe side to err on.
 *
 * ⓘ A home screen is normally seen only after unlock; this matters for
 * launchers that show widgets on the lock screen and for a phone handed over
 * between unlocks.
 */
object LockWatch {

    @Volatile
    private var registered = false

    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            refreshAll(context)
        }
    }

    fun ensure(context: Context) {
        if (registered) return
        synchronized(this) {
            if (registered) return
            try {
                val app = context.applicationContext
                val filter = IntentFilter().apply {
                    addAction(Intent.ACTION_SCREEN_OFF)
                    addAction(Intent.ACTION_USER_PRESENT)
                }
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    // System broadcasts still reach a not-exported receiver.
                    app.registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED)
                } else {
                    app.registerReceiver(receiver, filter)
                }
                registered = true
            } catch (t: Throwable) {
                Log.e("LockWatch", "lock watch not registered", t)
            }
        }
    }

    /**
     * Should secret texts be hidden right now? Locked with a PIN (isDeviceLocked),
     * the keyguard showing at all (a swipe lock too), or the screen off — at
     * SCREEN_OFF the lock itself may still be seconds away ("lock after 5 s"),
     * and no event comes when it lands.
     */
    fun locked(context: Context): Boolean = try {
        val km = context.getSystemService(Context.KEYGUARD_SERVICE) as? KeyguardManager
        val pm = context.getSystemService(Context.POWER_SERVICE) as? PowerManager
        (km?.isDeviceLocked ?: false) || (km?.isKeyguardLocked ?: false) || (pm?.isInteractive == false)
    } catch (t: Throwable) {
        true // not knowing is a reason to hide
    }

    /**
     * Redraw every placed instance of every kit widget: the same broadcast
     * home_widget's updateWidget sends, once per receiver that has instances.
     */
    fun refreshAll(context: Context) {
        try {
            val manager = AppWidgetManager.getInstance(context) ?: return
            WidgetKeys.RECEIVERS.values.forEach { cls ->
                val ids = manager.getAppWidgetIds(ComponentName(context, cls))
                if (ids != null && ids.isNotEmpty()) {
                    context.sendBroadcast(
                        Intent(context, cls).apply {
                            action = AppWidgetManager.ACTION_APPWIDGET_UPDATE
                            putExtra(AppWidgetManager.EXTRA_APPWIDGET_IDS, ids)
                        }
                    )
                }
            }
        } catch (t: Throwable) {
            Log.e("LockWatch", "refresh failed", t)
        }
    }
}
