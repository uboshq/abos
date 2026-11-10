package com.abos.abos_mobile.sms

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONArray
import org.json.JSONObject

/**
 * ⭐ অফিসের SMS ফোনের নিজের খাতা — সার্ভারের ঠিকানা, ফোনের চাবি, চালু কি না, আর সার্ভারকে এখনো না-জানানো খবর।
 * (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)
 *
 * ⓘ অ্যাপের নিজস্ব জায়গায় (MODE_PRIVATE), আর অ্যাপের ব্যাকআপ বন্ধ (manifest: allowBackup=false) — চাবি ফোনের বাইরে যায় না।
 *   চাবি হারালে বা ফোন হাতবদল হলে কন্ট্রোল প্যানেলে "নতুন চাবি" — পুরনোটা সার্ভারে সাথে সাথে মরে।
 * ⓘ খবরগুলো (sent / delivered / failed) প্রথমে এখানে জমে, তারপর সার্ভারে যায় — নেট না থাকলে হারায় না।
 */
class SmsGatewayStore(context: Context) {
    private val prefs: SharedPreferences = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    var baseUrl: String
        get() = prefs.getString("base_url", "") ?: ""
        set(value) = prefs.edit().putString("base_url", value.trimEnd('/')).apply()

    var key: String
        get() = prefs.getString("key", "") ?: ""
        set(value) = prefs.edit().putString("key", value.trim()).apply()

    var enabled: Boolean
        get() = prefs.getBoolean("enabled", false)
        set(value) = prefs.edit().putBoolean("enabled", value).apply()

    var appVersion: String
        get() = prefs.getString("app_version", "") ?: ""
        set(value) = prefs.edit().putString("app_version", value).apply()

    var lastRun: Long
        get() = prefs.getLong("last_run", 0L)
        set(value) = prefs.edit().putLong("last_run", value).apply()

    var lastError: String
        get() = prefs.getString("last_error", "") ?: ""
        set(value) = prefs.edit().putString("last_error", value.take(200)).apply()

    /** আজ এই ফোন থেকে কয়টা গেল — দিন বদলালে শূন্য থেকে */
    fun sentToday(): Int {
        val today = day()
        return if (prefs.getString("sent_day", "") == today) prefs.getInt("sent_count", 0) else 0
    }

    fun countSent() {
        val today = day()
        val now = if (prefs.getString("sent_day", "") == today) prefs.getInt("sent_count", 0) else 0
        prefs.edit().putString("sent_day", today).putInt("sent_count", now + 1).apply()
    }

    /** ⓘ একটা SMS কয়টা ভাগে গেছে — সব ভাগের খবর এলে তবেই "গেছে"; কোনো ভাগ না গেলে "যায়নি" */
    fun expectParts(id: String, parts: Int) {
        prefs.edit().putInt("parts_$id", parts).putInt("done_$id", 0).putBoolean("bad_$id", false).apply()
    }

    /** @return null = আরো ভাগের অপেক্ষা; নইলে চূড়ান্ত খবর (sent বা failed) */
    @Synchronized
    fun partSent(id: String, ok: Boolean): String? {
        val parts = prefs.getInt("parts_$id", 1)
        val done = prefs.getInt("done_$id", 0) + 1
        val bad = prefs.getBoolean("bad_$id", false) || !ok
        if (done < parts) {
            prefs.edit().putInt("done_$id", done).putBoolean("bad_$id", bad).apply()
            return null
        }
        prefs.edit().remove("parts_$id").remove("done_$id").remove("bad_$id").apply()
        return if (bad) "failed" else "sent"
    }

    @Synchronized
    fun addEvent(id: String, event: String, reason: String? = null) {
        val list = events()
        list.put(JSONObject().put("id", id).put("event", event).apply { if (reason != null) put("reason", reason.take(250)) })
        prefs.edit().putString("events", list.toString()).apply()
    }

    @Synchronized
    fun events(): JSONArray = runCatching { JSONArray(prefs.getString("events", "[]")) }.getOrDefault(JSONArray())

    /** সার্ভার যতগুলো নিয়েছে, ততগুলো সামনে থেকে বাদ */
    @Synchronized
    fun dropEvents(count: Int) {
        val list = events()
        val rest = JSONArray()
        for (i in count until list.length()) rest.put(list.get(i))
        prefs.edit().putString("events", rest.toString()).apply()
    }

    private fun day(): String = java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.US).format(java.util.Date())

    companion object {
        private const val FILE = "abos_sms_gateway"
    }
}
