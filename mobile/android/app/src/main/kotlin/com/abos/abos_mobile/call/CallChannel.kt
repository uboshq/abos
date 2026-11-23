package com.abos.abos_mobile.call

import android.Manifest
import android.app.Activity
import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.Ringtone
import android.media.RingtoneManager
import android.os.Build
import android.os.SystemClock
import android.os.VibrationEffect
import android.os.Vibrator
import android.view.WindowManager
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodChannel

/**
 * ⭐ একজনের সাথে ভয়েস/ভিডিও কল — ফোনের যে কাজগুলো Dart নিজে পারে না (বার্তা ও কল, প্রথম ধাপ; নমুনা 3nuwRUdn v5 "ঘ")।
 *
 * Dart → Kotlin (lib/features/messenger/call/call_device.dart):
 *  - `ask {video}` → মাইক (আর ভিডিওতে ক্যামেরা) চাওয়া; উত্তর `{mic, camera}`
 *  - `startOngoing {title, text, video}` / `stopOngoing` → চলতি কলের সামনের-সেবা ([CallService]) — পর্দা বন্ধ হলেও কথা চলে
 *  - `keepScreenOn {on}` → কলের সময় পর্দা জ্বলে থাকে
 *  - `ring {on}` → অ্যাপ সামনে থাকলে আসা কলের রিং (ফোনের নিজের রিংটোন, বারবার) আর কাঁপুনি
 *  - `showOverLock {on}` → ফোনের তালার পর্দার উপরে আসা কলের পাতা
 *  - `remindLater {id, title, body, payload, delayMs}` → "পরে মনে করান" — কিছুক্ষণ পরে একটা বার্তা ([CallReminderReceiver])
 *
 * ⓘ অনুমতির উত্তর আসে [MainActivity]-র মাধ্যমে ([onPermissionResult])।
 */
class CallChannel(private val activity: Activity, messenger: BinaryMessenger) {

    private val channel = MethodChannel(messenger, CHANNEL)
    private var askResult: MethodChannel.Result? = null
    private var askVideo = false
    private var ringtone: Ringtone? = null

    init {
        channel.setMethodCallHandler { call, result ->
            when (call.method) {
                "ask" -> ask(call.argument<Boolean>("video") ?: false, result)
                "startOngoing" -> {
                    CallService.start(
                        activity,
                        call.argument<String>("title").orEmpty(),
                        call.argument<String>("text").orEmpty(),
                        call.argument<Boolean>("video") ?: false,
                    )
                    result.success(true)
                }
                "stopOngoing" -> {
                    CallService.stop(activity)
                    result.success(true)
                }
                "keepScreenOn" -> {
                    val on = call.argument<Boolean>("on") ?: false
                    activity.runOnUiThread {
                        if (on) {
                            activity.window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                        } else {
                            activity.window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                        }
                        result.success(null)
                    }
                }
                "ring" -> {
                    ring(call.argument<Boolean>("on") ?: false)
                    result.success(null)
                }
                "showOverLock" -> {
                    val on = call.argument<Boolean>("on") ?: false
                    activity.runOnUiThread {
                        showOverLock(activity, on)
                        result.success(null)
                    }
                }
                "remindLater" -> {
                    remindLater(
                        activity,
                        call.argument<Int>("id") ?: 0,
                        call.argument<String>("title").orEmpty(),
                        call.argument<String>("body").orEmpty(),
                        call.argument<String>("payload").orEmpty(),
                        (call.argument<Number>("delayMs") ?: 600_000).toLong(),
                    )
                    result.success(true)
                }
                else -> result.notImplemented()
            }
        }
    }

    private fun granted(permission: String) =
        activity.checkSelfPermission(permission) == PackageManager.PERMISSION_GRANTED

    private fun ask(video: Boolean, result: MethodChannel.Result) {
        val wanted = buildList {
            if (!granted(Manifest.permission.RECORD_AUDIO)) add(Manifest.permission.RECORD_AUDIO)
            if (video && !granted(Manifest.permission.CAMERA)) add(Manifest.permission.CAMERA)
        }
        if (wanted.isEmpty()) {
            result.success(mapOf("mic" to true, "camera" to (!video || granted(Manifest.permission.CAMERA))))
            return
        }
        askResult?.success(mapOf("mic" to false, "camera" to false))
        askResult = result
        askVideo = video
        activity.requestPermissions(wanted.toTypedArray(), PERMISSION_REQUEST)
    }

    /** [MainActivity.onRequestPermissionsResult] থেকে */
    fun onPermissionResult() {
        val result = askResult ?: return
        askResult = null
        result.success(
            mapOf(
                "mic" to granted(Manifest.permission.RECORD_AUDIO),
                "camera" to (!askVideo || granted(Manifest.permission.CAMERA)),
            )
        )
    }

    private fun ring(on: Boolean) {
        val vibrator = activity.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        if (!on) {
            ringtone?.stop()
            ringtone = null
            vibrator?.cancel()
            return
        }
        if (ringtone?.isPlaying == true) return
        try {
            val uri = RingtoneManager.getActualDefaultRingtoneUri(activity, RingtoneManager.TYPE_RINGTONE)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
            ringtone = RingtoneManager.getRingtone(activity, uri)?.also { r ->
                r.audioAttributes = AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) r.isLooping = true
                r.play()
            }
        } catch (_: Exception) {
            // ⓘ রিংটোন না বাজলেও পাতা আর কাঁপুনি থাকে
        }
        try {
            val pattern = longArrayOf(0, 800, 1200)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                vibrator?.vibrate(VibrationEffect.createWaveform(pattern, 0))
            } else {
                @Suppress("DEPRECATION") vibrator?.vibrate(pattern, 0)
            }
        } catch (_: Exception) {
        }
    }

    fun dispose() {
        ring(false)
        askResult?.success(mapOf("mic" to false, "camera" to false))
        askResult = null
        channel.setMethodCallHandler(null)
    }

    companion object {
        const val CHANNEL = "com.abos.abos_mobile/call"
        const val PERMISSION_REQUEST = 7310

        /** flutter_local_notifications-এর নিজের চাপের ধরন — একই ধরনে এলে Dart-এর একই পথ (LocalAlerts.handleTap) */
        private const val SELECT_NOTIFICATION = "SELECT_NOTIFICATION"
        private const val SELECT_FOREGROUND_NOTIFICATION = "SELECT_FOREGROUND_NOTIFICATION"

        fun showOverLock(activity: Activity, on: Boolean) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                activity.setShowWhenLocked(on)
                activity.setTurnScreenOn(on)
            } else {
                @Suppress("DEPRECATION")
                val flags = WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                if (on) activity.window.addFlags(flags) else activity.window.clearFlags(flags)
            }
        }

        /**
         * ⭐ আসা কলের বার্তা (পূর্ণ-পর্দা) চেপে বা নিজে থেকে খুলল — তালার পর্দার উপরে দেখানো। ⓘ কেবল কলের বার্তায়; অন্য
         * সব বার্তা আগের মতো তালার নিচে থাকে। কল শেষ হলে Dart `showOverLock {on: false}` ডাকে।
         */
        fun wakeForCall(activity: Activity, intent: Intent?) {
            if (intent == null) return
            val action = intent.action
            if (action != SELECT_NOTIFICATION && action != SELECT_FOREGROUND_NOTIFICATION) return
            val payload = intent.getStringExtra("payload").orEmpty()
            if (payload.startsWith("messenger:call")) showOverLock(activity, true)
        }

        fun remindLater(context: Context, id: Int, title: String, body: String, payload: String, delayMs: Long) {
            val intent = Intent(context, CallReminderReceiver::class.java)
                .putExtra(CallReminderReceiver.EXTRA_ID, id)
                .putExtra(CallReminderReceiver.EXTRA_TITLE, title)
                .putExtra(CallReminderReceiver.EXTRA_BODY, body)
                .putExtra(CallReminderReceiver.EXTRA_PAYLOAD, payload)
            val pending = PendingIntent.getBroadcast(
                context, id, intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
            val alarms = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val at = SystemClock.elapsedRealtime() + delayMs
            // ⓘ ঠিক ঘড়ির কাঁটায় নয় (বাড়তি অনুমতি লাগত) — "কিছুক্ষণ পরে", ফোন ঘুমিয়ে থাকলেও
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                alarms.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending)
            } else {
                alarms.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending)
            }
        }

        /** মনে করানোর বার্তা চাপলে — flutter_local_notifications-এর চাপের মতো, তাই Dart একই পথে খোলে */
        fun openIntent(context: Context, id: Int, payload: String): Intent =
            (context.packageManager.getLaunchIntentForPackage(context.packageName) ?: Intent())
                .setAction(SELECT_NOTIFICATION)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra("notificationId", id)
                .putExtra("payload", payload)
    }
}
