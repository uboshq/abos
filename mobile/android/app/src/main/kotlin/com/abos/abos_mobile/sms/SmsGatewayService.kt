package com.abos.abos_mobile.sms

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
import android.telephony.SmsManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * ⭐ অফিসের SMS ফোন — মিনিটে একবার ERP-র সারি থেকে SMS নেয়, নিজের সিমে পাঠায়, তারপর "গেছে / পৌঁছেছে / যায়নি" জানায়।
 * (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২; সার্ভারের দিক: SmsGatewayController, SmsOutboxService)
 *
 * ⓘ সামনের-সেবা (foreground service) — স্থায়ী একটা নোটিশ "SMS গেটওয়ে চলছে" থাকে, যাতে Android অ্যাপ বন্ধ থাকলেও কাজটা না মারে।
 *   ধরন specialUse: dataSync-এর দিনে ৬ ঘণ্টার সীমা (Android 15) এখানে চলে না, এটা সারাদিনের কাজ।
 * ⓘ নেট না থাকলে চুপচাপ পরের মিনিটে আবার; খবর ফোনে জমে থাকে ([[SmsGatewayStore]]) আর পরে যায়।
 * ⛔ সার্ভার ৪০১ দিলে (চাবি বদলেছে বা ফোন বন্ধ করা হয়েছে) সেবা নিজেই থামে — ভুল চাবিতে সারাদিন দরজা ঠোকা নয়।
 */
class SmsGatewayService : Service() {
    @Volatile private var running = false
    private var worker: Thread? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val store = SmsGatewayStore(this)
        if (!store.enabled || store.key.isEmpty() || store.baseUrl.isEmpty()) {
            stopSelf()
            return START_NOT_STICKY
        }

        startAsForeground(store)

        if (!running) {
            running = true
            worker = Thread({ loop() }, "abos-sms-gateway").apply { isDaemon = true; start() }
        }

        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        worker?.interrupt()
        super.onDestroy()
    }

    private fun loop() {
        val store = SmsGatewayStore(this)
        while (running && store.enabled) {
            runCatching { round(store) }.onFailure { store.lastError = it.javaClass.simpleName + ": " + (it.message ?: "") }
            store.lastRun = System.currentTimeMillis()
            updateNotice(store)
            try {
                Thread.sleep(INTERVAL_MS)
            } catch (_: InterruptedException) {
                break
            }
        }
    }

    /** একবারের কাজ: জমা খবর পাঠানো, সারি থেকে নেওয়া, পাঠানো */
    private fun round(store: SmsGatewayStore) {
        flush(store)

        val answer = post(store, "/api/v1/sms-gateway/take", JSONObject().put("app_version", store.appVersion))
        if (answer == null) return

        val messages = answer.optJSONArray("messages") ?: JSONArray()
        for (i in 0 until messages.length()) {
            val m = messages.getJSONObject(i)
            send(store, m.getString("id"), m.getString("phone"), m.getString("body"))
        }
        store.lastError = ""
    }

    @Suppress("DEPRECATION")
    private fun send(store: SmsGatewayStore, id: String, phone: String, body: String) {
        if (checkSelfPermission(Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            store.addEvent(id, "failed", "the phone has not allowed ABOS to send SMS")
            return
        }

        val sms: SmsManager = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) getSystemService(SmsManager::class.java) else SmsManager.getDefault()
        val parts = sms.divideMessage(body)
        store.expectParts(id, parts.size)

        val sent = ArrayList<PendingIntent>()
        val delivered = ArrayList<PendingIntent>()
        parts.forEachIndexed { index, _ ->
            sent.add(result(SmsResultReceiver.SENT, id, index))
            // ⓘ পৌঁছানোর খবর কেবল শেষ ভাগের — পুরো লেখা পৌঁছেছে বলার জন্য সেটাই যথেষ্ট
            delivered.add(result(SmsResultReceiver.DELIVERED, id, if (index == parts.size - 1) index else -1))
        }

        try {
            sms.sendMultipartTextMessage(phone, null, parts, sent, delivered)
        } catch (e: Exception) {
            store.addEvent(id, "failed", e.javaClass.simpleName)
        }
    }

    private fun result(action: String, id: String, part: Int): PendingIntent {
        val intent = Intent(this, SmsResultReceiver::class.java).setAction(action).putExtra("id", id).putExtra("part", part)
        // ⓘ প্রতিটা ভাগের নিজের PendingIntent — একই হলে Android আগেরটাকেই ফেরত দিত, আর খবর গুলিয়ে যেত
        val code = (id + action + part).hashCode()
        return PendingIntent.getBroadcast(this, code, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    /** জমা খবর সার্ভারে — একবারে ১০০টা পর্যন্ত */
    private fun flush(store: SmsGatewayStore) {
        val all = store.events()
        if (all.length() == 0) return
        val batch = JSONArray()
        for (i in 0 until minOf(all.length(), 100)) batch.put(all.get(i))
        if (post(store, "/api/v1/sms-gateway/report", JSONObject().put("events", batch)) != null) {
            store.dropEvents(batch.length())
        }
    }

    /** @return উত্তর, বা null (নেট নেই বা সার্ভারের ভুল) */
    private fun post(store: SmsGatewayStore, path: String, body: JSONObject): JSONObject? {
        val connection = (URL(store.baseUrl + path).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 15_000
            readTimeout = 20_000
            doOutput = true
            setRequestProperty("Authorization", "Bearer " + store.key)
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json")
        }
        try {
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val code = connection.responseCode
            if (code == 401) {
                // ⛔ চাবি আর চলে না — থামা, আর পর্দায় কারণ
                store.lastError = "401"
                store.enabled = false
                running = false
                stopSelf()
                return null
            }
            if (code !in 200..299) {
                store.lastError = "HTTP $code"
                return null
            }
            val text = connection.inputStream.bufferedReader(Charsets.UTF_8).use { it.readText() }
            return JSONObject(text)
        } finally {
            connection.disconnect()
        }
    }

    private fun startAsForeground(store: SmsGatewayStore) {
        val notice = notice(store)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTICE_ID, notice, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTICE_ID, notice)
        }
    }

    private fun updateNotice(store: SmsGatewayStore) {
        (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager).notify(NOTICE_ID, notice(store))
    }

    private fun notice(store: SmsGatewayStore): Notification {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL, "SMS gateway", NotificationManager.IMPORTANCE_LOW))
        }
        val open = packageManager.getLaunchIntentForPackage(packageName)?.let {
            PendingIntent.getActivity(this, 0, it, PendingIntent.FLAG_IMMUTABLE)
        }
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(this, CHANNEL) else @Suppress("DEPRECATION") Notification.Builder(this)
        return builder
            .setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentTitle("ABOS SMS")
            .setContentText(if (store.lastError.isEmpty()) "SMS: ${store.sentToday()}" else "SMS: ${store.sentToday()} · ${store.lastError}")
            .setOngoing(true)
            .setContentIntent(open)
            .build()
    }

    companion object {
        private const val CHANNEL = "abos_sms_gateway"
        private const val NOTICE_ID = 7301
        private const val INTERVAL_MS = 60_000L

        fun start(context: Context) {
            val intent = Intent(context, SmsGatewayService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.startForegroundService(intent) else context.startService(intent)
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, SmsGatewayService::class.java))
        }
    }
}
