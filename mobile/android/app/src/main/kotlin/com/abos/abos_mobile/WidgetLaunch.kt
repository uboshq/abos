package com.abos.abos_mobile

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri

/**
 * The tap on a home-screen widget, built here instead of by home_widget.
 *
 * home_widget 0.7.0 (HomeWidgetLaunchIntent.getActivity) adds an options
 * bundle with MODE_BACKGROUND_ACTIVITY_START_ALLOWED on Android 14 and up.
 * That mode is deprecated at target SDK 36, and a rejection there throws from
 * inside the widget provider, which runs in the app's own process: suspected
 * cause of "the app opens and closes at once" in 0.4.0 (30 Sep 2026). A tap on
 * one of our own widgets does not need a background-start exemption; the
 * launcher starts it.
 *
 * Same action and data as home_widget, so the Dart side
 * (HomeWidget.widgetClicked / initiallyLaunchedFromHomeWidget) still knows
 * the tap came from a widget and which one.
 */
object WidgetLaunch {
    private const val HOME_WIDGET_LAUNCH_ACTION = "es.antonborri.home_widget.action.LAUNCH"

    fun open(context: Context, uri: Uri): PendingIntent {
        val intent = Intent(context, MainActivity::class.java).apply {
            action = HOME_WIDGET_LAUNCH_ACTION
            data = uri
        }

        // A request code per destination, so the two widgets never share one.
        return PendingIntent.getActivity(
            context,
            uri.hashCode(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }
}
