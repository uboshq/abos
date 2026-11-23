package com.abos.abos_mobile.call

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder

/**
 * ⭐ চলতি কলের সামনের-সেবা — পর্দা বন্ধ হলে বা অন্য অ্যাপে গেলেও মাইক (আর ভিডিওতে ক্যামেরা) চলে; ট্রেতে "কল চলছে" বার্তা,
 * চাপলে কলের পাতায় ফেরা। কল শেষ হলে Dart বন্ধ করে ([CallChannel] `stopOngoing`)।
 *
 * ⓘ ধরন microphone (আর ক্যামেরার অনুমতি থাকলে camera) — Android 14 থেকে ধরন না মিললে সেবা ওঠে না।
 */
class CallService : Service() {

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val title = intent?.getStringExtra(EXTRA_TITLE).orEmpty()
        val text = intent?.getStringExtra(EXTRA_TEXT).orEmpty()
        val video = intent?.getBooleanExtra(EXTRA_VIDEO, false) ?: false
        val notice = notice(title, text)
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                var type = 0
                if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                    type = type or ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
                }
                if (video && checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                    type = type or ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA
                }
                if (type == 0) {
                    stopSelf()
                    return START_NOT_STICKY
                }
                startForeground(NOTICE_ID, notice, type)
            } else {
                startForeground(NOTICE_ID, notice)
            }
        } catch (_: Exception) {
            // ⓘ সেবা না উঠলেও কল চলে — কেবল পর্দা বন্ধ হলে থামতে পারে
            stopSelf()
        }
        return START_NOT_STICKY
    }

    private fun notice(title: String, text: String): Notification {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL, "ABOS call", NotificationManager.IMPORTANCE_LOW))
        }
        val open = packageManager.getLaunchIntentForPackage(packageName)?.let {
            it.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
            PendingIntent.getActivity(this, 0, it, PendingIntent.FLAG_IMMUTABLE)
        }
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(this, CHANNEL) else @Suppress("DEPRECATION") Notification.Builder(this)
        return builder
            .setSmallIcon(android.R.drawable.sym_call_outgoing)
            .setContentTitle(title.ifEmpty { "ABOS" })
            .setContentText(text)
            .setCategory(Notification.CATEGORY_CALL)
            .setOngoing(true)
            .setContentIntent(open)
            .build()
    }

    companion object {
        private const val CHANNEL = "abos_call_ongoing"
        private const val NOTICE_ID = 7311
        private const val EXTRA_TITLE = "title"
        private const val EXTRA_TEXT = "text"
        private const val EXTRA_VIDEO = "video"

        fun start(context: Context, title: String, text: String, video: Boolean) {
            val intent = Intent(context, CallService::class.java)
                .putExtra(EXTRA_TITLE, title)
                .putExtra(EXTRA_TEXT, text)
                .putExtra(EXTRA_VIDEO, video)
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.startForegroundService(intent) else context.startService(intent)
            } catch (_: Exception) {
            }
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, CallService::class.java))
        }
    }
}
