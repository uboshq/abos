package com.abos.abos_mobile.call

import android.app.Notification
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build

/**
 * ⭐ "পরে মনে করান" — আসা কল কেটে দেওয়ার কিছুক্ষণ পরে একটা সাধারণ বার্তা ("জরুরি বার্তা" চ্যানেলে); চাপলে সেই মানুষের চ্যাট
 * (payload Dart-এর একই পথে যায় — [CallChannel.openIntent])।
 */
class CallReminderReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getIntExtra(EXTRA_ID, 0)
        val title = intent.getStringExtra(EXTRA_TITLE).orEmpty()
        val body = intent.getStringExtra(EXTRA_BODY).orEmpty()
        val payload = intent.getStringExtra(EXTRA_PAYLOAD).orEmpty()
        val open = PendingIntent.getActivity(
            context, id, CallChannel.openIntent(context, id, payload),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val icon = context.resources.getIdentifier("ic_launcher_monochrome", "mipmap", context.packageName)
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(context, ALERTS_CHANNEL)
        } else {
            @Suppress("DEPRECATION") Notification.Builder(context).setPriority(Notification.PRIORITY_HIGH)
        }
        val notice = builder
            .setSmallIcon(if (icon != 0) icon else android.R.drawable.sym_call_missed)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setContentIntent(open)
            .build()
        try {
            (context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager).notify(id, notice)
        } catch (_: SecurityException) {
            // ⓘ বার্তার অনুমতি নেই — কিছু নয়
        }
    }

    companion object {
        /** lib/core/push/local_alerts.dart-এর alertsChannelId — অ্যাপ শুরুতেই চ্যানেলটা বানায় */
        private const val ALERTS_CHANNEL = "abos_alerts"
        const val EXTRA_ID = "id"
        const val EXTRA_TITLE = "title"
        const val EXTRA_BODY = "body"
        const val EXTRA_PAYLOAD = "payload"
    }
}
