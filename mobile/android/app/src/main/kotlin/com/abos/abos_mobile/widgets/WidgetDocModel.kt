package com.abos.abos_mobile.widgets

import org.json.JSONArray
import org.json.JSONObject
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * The WidgetDoc JSON (v:1) read back — the Kotlin mirror of
 * lib/core/launcher_widgets/kit/widget_doc.dart. It formats nothing but the
 * asOf time: every text arrives already written by Dart.
 *
 * [parse] never throws: a document it cannot read is null, and null draws the
 * calm placeholder, never a crash in the app's own process.
 */
enum class Tone { PLAIN, GOOD, BAD, WARN, MUTED;

    companion object {
        fun of(name: String?): Tone = when (name) {
            "good" -> GOOD
            "bad" -> BAD
            "warn" -> WARN
            "muted" -> MUTED
            else -> PLAIN
        }
    }
}

data class DocText(val text: String, val secret: Boolean, val tone: Tone)
data class DocHero(val figure: DocText, val caption: DocText?)
data class DocRow(val title: DocText, val second: DocText?, val amount: DocText?, val chip: DocText?, val link: String?)
data class DocBar(val label: DocText, val fraction: Float, val value: DocText?, val tone: Tone)
data class DocAction(val label: String, val icon: String, val link: String)

data class WidgetDocModel(
    val title: String,
    /** Epoch millis, UTC; null when the time could not be read. */
    val asOf: Long?,
    val link: String?,
    val hero: DocHero?,
    val rows: List<DocRow>,
    val bars: List<DocBar>,
    val actions: List<DocAction>,
    val empty: String?,
    val more: String?,
    /** A small text at the right of the header ("7", "চালু 3") — secret-able like any text. */
    val corner: DocText? = null,
) {
    companion object {
        fun parse(json: String?): WidgetDocModel? {
            if (json.isNullOrBlank()) return null
            return try {
                val o = JSONObject(json)
                if (o.optInt("v", 1) != 1) return null
                WidgetDocModel(
                    title = o.optString("title", ""),
                    asOf = parseIsoUtc(o.optString("asOf", "")),
                    link = o.str("link"),
                    hero = o.optJSONObject("hero")?.let { h ->
                        val figure = text(h.optJSONObject("figure")) ?: return@let null
                        DocHero(figure, text(h.optJSONObject("caption")))
                    },
                    rows = o.optJSONArray("rows").objects().mapNotNull { r ->
                        val title = text(r.optJSONObject("title")) ?: return@mapNotNull null
                        DocRow(
                            title,
                            text(r.optJSONObject("second")),
                            text(r.optJSONObject("amount")),
                            text(r.optJSONObject("chip")),
                            r.str("link"),
                        )
                    },
                    bars = o.optJSONArray("bars").objects().mapNotNull { b ->
                        val label = text(b.optJSONObject("label")) ?: return@mapNotNull null
                        val f = b.optDouble("fraction", 0.0).let { if (it.isNaN()) 0.0 else it }
                        DocBar(label, f.coerceIn(0.0, 1.0).toFloat(), text(b.optJSONObject("value")), Tone.of(b.str("c")))
                    },
                    actions = o.optJSONArray("actions").objects().mapNotNull { a ->
                        val label = a.str("label") ?: return@mapNotNull null
                        val link = a.str("link") ?: return@mapNotNull null
                        DocAction(label, a.str("icon") ?: "", link)
                    },
                    empty = o.str("empty"),
                    more = o.str("more"),
                    corner = text(o.optJSONObject("corner")),
                )
            } catch (t: Throwable) {
                null
            }
        }

        private fun text(o: JSONObject?): DocText? {
            if (o == null) return null
            val t = o.str("t") ?: return null
            return DocText(t, o.optBoolean("s", false), Tone.of(o.str("c")))
        }

        private fun JSONObject.str(name: String): String? =
            if (has(name) && !isNull(name)) optString(name, "").ifEmpty { null } else null

        private fun JSONArray?.objects(): List<JSONObject> {
            if (this == null) return emptyList()
            return (0 until length()).mapNotNull { optJSONObject(it) }
        }

        private val ISO = Regex("""^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?Z$""")

        /** Dart's `toUtc().toIso8601String()` — "2026-10-20T04:42:00.000Z" (fraction optional, any length). */
        fun parseIsoUtc(s: String): Long? {
            val m = ISO.matchEntire(s.trim()) ?: return null
            val g = m.groupValues
            val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC")).apply {
                clear()
                set(g[1].toInt(), g[2].toInt() - 1, g[3].toInt(), g[4].toInt(), g[5].toInt(), g[6].toInt())
            }
            val millis = g[7].takeIf { it.isNotEmpty() }?.drop(1)?.padEnd(3, '0')?.take(3)?.toInt() ?: 0
            return cal.timeInMillis + millis
        }
    }
}

/**
 * Numbers and times on the widget in the person's language (money stays as
 * Dart wrote it, English digits): Bangla digits in Bangla, as before; English
 * digits in English (0.4.43).
 */
object BnTime {
    private const val DIGITS = "০১২৩৪৫৬৭৮৯"

    fun digits(s: String, english: Boolean = false): String = if (english) s else buildString {
        s.forEach { c -> append(if (c in '0'..'9') DIGITS[c - '0'] else c) }
    }

    /** "১০:৪২" / "10:42" today (12-hour, as clocks are read here), the word for yesterday, else "১৮/১০" / "18/10". */
    fun short(asOf: Long, now: Long, words: Words): String {
        val then = Calendar.getInstance().apply { timeInMillis = asOf }
        val today = Calendar.getInstance().apply { timeInMillis = now }
        fun sameDay(a: Calendar, b: Calendar) =
            a.get(Calendar.YEAR) == b.get(Calendar.YEAR) && a.get(Calendar.DAY_OF_YEAR) == b.get(Calendar.DAY_OF_YEAR)
        if (sameDay(then, today)) {
            val h = then.get(Calendar.HOUR).let { if (it == 0) 12 else it }
            return digits(String.format(Locale.ROOT, "%d:%02d", h, then.get(Calendar.MINUTE)), words.english)
        }
        val yesterday = (today.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, -1) }
        if (sameDay(then, yesterday)) return words.yesterday
        return digits(String.format(Locale.ROOT, "%d/%d", then.get(Calendar.DAY_OF_MONTH), then.get(Calendar.MONTH) + 1), words.english)
    }
}
