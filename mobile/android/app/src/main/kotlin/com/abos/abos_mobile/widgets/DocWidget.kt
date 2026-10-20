package com.abos.abos_mobile.widgets

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.DpSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.datastore.core.DataStore
import androidx.glance.ColorFilter
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.GlanceTheme
import androidx.glance.Image
import androidx.glance.ImageProvider
import androidx.glance.LocalContext
import androidx.glance.LocalSize
import androidx.glance.action.Action
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.LinearProgressIndicator
import androidx.glance.appwidget.SizeMode
import androidx.glance.appwidget.action.actionStartActivity
import androidx.glance.appwidget.appWidgetBackground
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.background
import androidx.glance.color.ColorProvider
import androidx.glance.currentState
import androidx.glance.layout.Alignment
import androidx.glance.layout.Box
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.size
import androidx.glance.layout.width
import androidx.glance.state.GlanceStateDefinition
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextAlign
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import com.abos.abos_mobile.MainActivity
import com.abos.abos_mobile.R
import es.antonborri.home_widget.HomeWidgetPlugin
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow
import java.io.File

/**
 * ⭐ The one renderer of the widget kit (20 Oct 2026): draws any WidgetDoc, for
 * every one of the 21 widgets, at every size.
 *
 * Division of labour, as with the two older widgets: Dart reads the server,
 * words every figure (Money.taka, names as "নাম · পয়েন্ট · শাখা") and writes
 * one JSON per widget; this reads it back and lays it out. No network, no
 * arithmetic, no money formatting.
 *
 * What it decides itself: how much fits (by size class), the colours (the
 * phone's Material You palette through GlanceTheme, light and dark), whether
 * the figures are old (asOf over 60 minutes: everything muted, a "পুরনো" chip),
 * and whether secret texts show (see [LockWatch]).
 *
 * ⚠️ "Old" is judged when the widget is drawn. Widgets have no clock of their
 * own here (updatePeriodMillis 0), so a widget left alone does not grey itself
 * at minute 61; it does at its next draw — every lock, every unlock, every
 * refresh from the app.
 */
class DocWidget(private val key: String) : GlanceAppWidget() {

    override val sizeMode = SizeMode.Responsive(
        setOf(SMALL_LINE, SMALL_SQUARE, WIDE_LINE, WIDE_BOX, TALL)
    )

    override val stateDefinition: GlanceStateDefinition<DocSnapshot> = DocStateDefinition(key)

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        provideContent {
            GlanceTheme {
                Body(key, currentState())
            }
        }
    }

    companion object {
        /*
         * ⓘ The five size classes. Glance picks, for each size the launcher
         * offers, the largest class that fits, so the classes sit a little
         * below the nominal cell sizes (2 cells ≈ 110dp, 4 ≈ 250dp, 1 ≈ 40dp,
         * 6 ≈ 390dp): a four-cell widget on a narrow phone is ~230dp wide, and
         * at exactly 250 it would fall back to the two-cell layout.
         */
        val SMALL_LINE = DpSize(110.dp, 40.dp)   // 2x1
        val SMALL_SQUARE = DpSize(110.dp, 110.dp) // 2x2
        val WIDE_LINE = DpSize(200.dp, 40.dp)    // 4x1
        val WIDE_BOX = DpSize(200.dp, 110.dp)    // 4x2
        val TALL = DpSize(200.dp, 300.dp)        // 4x6

        const val STALE_MILLIS = 60L * 60L * 1000L
        const val HIDDEN = "••••"
        const val PLACEHOLDER = "খুলে একবার সিঙ্ক করুন"
        const val STALE_CHIP = "পুরনো"
    }
}

/**
 * What one draw reads: the doc, the owner's switch, the lock, the clock.
 * Deliberately no equals(): every read is a new value, so Glance recomposes on
 * every update even when the JSON is the same (the lock may have changed).
 */
class DocSnapshot(val json: String?, val amountsHidden: Boolean, val locked: Boolean, val now: Long)

/**
 * The snapshot comes straight from home_widget's SharedPreferences on every
 * read; Glance's own state file is never written (there is nothing to keep).
 */
class DocStateDefinition(private val key: String) : GlanceStateDefinition<DocSnapshot> {

    override suspend fun getDataStore(context: Context, fileKey: String): DataStore<DocSnapshot> =
        object : DataStore<DocSnapshot> {
            override val data: Flow<DocSnapshot>
                get() = flow { emit(read(context.applicationContext)) }

            override suspend fun updateData(transform: suspend (t: DocSnapshot) -> DocSnapshot): DocSnapshot =
                transform(read(context.applicationContext))
        }

    /** Nothing is stored, so nothing is there to delete when a widget is removed. */
    override fun getLocation(context: Context, fileKey: String): File =
        File(context.cacheDir, "abos_doc_widget_unused_$fileKey")

    private fun read(context: Context): DocSnapshot = try {
        val prefs = HomeWidgetPlugin.getData(context)
        DocSnapshot(
            json = prefs.getString(WidgetKeys.DOC_PREFIX + key, null),
            amountsHidden = prefs.getString(WidgetKeys.AMOUNTS_HIDDEN, null) == "1",
            locked = LockWatch.locked(context),
            now = System.currentTimeMillis(),
        )
    } catch (t: Throwable) {
        DocSnapshot(null, amountsHidden = true, locked = true, now = System.currentTimeMillis())
    }
}

// ---------------------------------------------------------------- taps

private const val HOME_WIDGET_LAUNCH_ACTION = "es.antonborri.home_widget.action.LAUNCH"

/** `abos://widget/open?path=<link>`, or `?key=<key>` when the doc names no link. */
internal fun openUri(key: String, link: String?): Uri {
    val b = Uri.Builder().scheme("abos").authority("widget").path("/open")
    if (link.isNullOrEmpty()) b.appendQueryParameter("key", key) else b.appendQueryParameter("path", link)
    return b.build()
}

/**
 * The same explicit intent WidgetLaunch.open builds (MainActivity, home_widget's
 * LAUNCH action, the uri as data), so HomeWidget.widgetClicked on the Dart side
 * receives it. See WidgetLaunch for why there is no background-start options
 * bundle.
 */
private fun openAction(context: Context, key: String, link: String?): Action =
    actionStartActivity(
        Intent(context, MainActivity::class.java).apply {
            action = HOME_WIDGET_LAUNCH_ACTION
            data = openUri(key, link)
        }
    )

// ---------------------------------------------------------------- colours

private data class Look(val stale: Boolean, val hide: Boolean, val now: Long = 0L, val wide: Boolean = false)

private val GOOD = ColorProvider(day = Color(0xFF1B6E33), night = Color(0xFF86D99C))
private val BAD = ColorProvider(day = Color(0xFFB3261E), night = Color(0xFFF2B8B5))
private val WARN = ColorProvider(day = Color(0xFF835400), night = Color(0xFFFFC56B))

@Composable
private fun toneColor(tone: Tone, look: Look): ColorProvider {
    if (look.stale) return GlanceTheme.colors.onSurfaceVariant
    return when (tone) {
        Tone.PLAIN -> GlanceTheme.colors.onSurface
        Tone.GOOD -> GOOD
        Tone.BAD -> BAD
        Tone.WARN -> WARN
        Tone.MUTED -> GlanceTheme.colors.onSurfaceVariant
    }
}

private fun shown(t: DocText, look: Look): String = if (t.secret && look.hide) DocWidget.HIDDEN else t.text

@Composable
private fun DText(
    t: DocText,
    look: Look,
    size: Int,
    bold: Boolean = false,
    muted: Boolean = false,
    modifier: GlanceModifier = GlanceModifier,
    align: TextAlign = TextAlign.Start,
) {
    Text(
        text = shown(t, look),
        modifier = modifier,
        maxLines = 1,
        style = TextStyle(
            color = if (muted && t.tone == Tone.PLAIN) GlanceTheme.colors.onSurfaceVariant else toneColor(t.tone, look),
            fontSize = size.sp,
            fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
            textAlign = align,
        ),
    )
}

@Composable
private fun PlainText(text: String, size: Int, color: ColorProvider, bold: Boolean = false, modifier: GlanceModifier = GlanceModifier) {
    Text(
        text = text,
        modifier = modifier,
        maxLines = 1,
        style = TextStyle(color = color, fontSize = size.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal),
    )
}

// ---------------------------------------------------------------- layout

@Composable
private fun Body(key: String, snap: DocSnapshot) {
    val context = LocalContext.current
    val doc = WidgetDocModel.parse(snap.json)
    val root = GlanceModifier
        .fillMaxSize()
        .appWidgetBackground()
        .background(GlanceTheme.colors.widgetBackground)
        .cornerRadius(16.dp)
        .padding(horizontal = 12.dp, vertical = 8.dp)
        .clickable(openAction(context, key, doc?.link))

    if (doc == null) {
        Placeholder(key, root)
        return
    }
    val size = LocalSize.current
    val wide = size.width >= DocWidget.WIDE_LINE.width
    val look = Look(
        stale = doc.asOf == null || snap.now - doc.asOf > DocWidget.STALE_MILLIS,
        hide = snap.locked || snap.amountsHidden,
        now = snap.now,
        wide = wide,
    )
    when {
        size.height < DocWidget.SMALL_SQUARE.height -> OneLine(key, doc, look, wide, root)
        size.height < DocWidget.TALL.height -> Boxed(key, doc, look, if (wide) 3 else 2, root)
        else -> Tall(key, doc, look, root)
    }
}

@Composable
private fun Placeholder(key: String, root: GlanceModifier) {
    Column(modifier = root, verticalAlignment = Alignment.CenterVertically, horizontalAlignment = Alignment.CenterHorizontally) {
        PlainText(WidgetKeys.LABELS[key] ?: "ABOS", 13, GlanceTheme.colors.onSurface, bold = true)
        PlainText(DocWidget.PLACEHOLDER, 12, GlanceTheme.colors.onSurfaceVariant)
    }
}

@Composable
private fun Header(doc: WidgetDocModel, look: Look) {
    Row(modifier = GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        PlainText(
            doc.title, 13,
            if (look.stale) GlanceTheme.colors.onSurfaceVariant else GlanceTheme.colors.onSurface,
            bold = true, modifier = GlanceModifier.defaultWeight(),
        )
        if (look.stale) {
            Chip(DocText(DocWidget.STALE_CHIP, false, Tone.WARN), Look(stale = false, hide = false))
            Spacer(GlanceModifier.width(4.dp))
        }
        /* The corner ("7", "চালু 3") sits right; on a two-column widget it takes
           the asOf time's place, on four columns the time follows it. */
        doc.corner?.let {
            DText(it, look, 12, bold = true, align = TextAlign.End)
        }
        val showTime = doc.asOf != null && (doc.corner == null || look.wide)
        if (showTime && doc.corner != null) Spacer(GlanceModifier.width(6.dp))
        if (showTime) PlainText(BnTime.short(doc.asOf!!, look.now), 11, GlanceTheme.colors.onSurfaceVariant)
    }
}

@Composable
private fun Chip(t: DocText, look: Look) {
    Box(
        modifier = GlanceModifier
            .background(GlanceTheme.colors.secondaryContainer)
            .cornerRadius(8.dp)
            .padding(horizontal = 6.dp, vertical = 1.dp),
    ) {
        Text(
            text = shown(t, look),
            maxLines = 1,
            style = TextStyle(
                color = if (t.tone == Tone.PLAIN || look.stale) GlanceTheme.colors.onSecondaryContainer else toneColor(t.tone, look),
                fontSize = 10.sp,
                fontWeight = FontWeight.Medium,
            ),
        )
    }
}

@Composable
private fun Hero(hero: DocHero, look: Look, figureSize: Int) {
    Column(modifier = GlanceModifier.fillMaxWidth()) {
        DText(hero.figure, look, figureSize, bold = true)
        hero.caption?.let { DText(it, look, 11, muted = true) }
    }
}

@Composable
private fun RowLine(key: String, row: DocRow, look: Look, withSecond: Boolean) {
    val context = LocalContext.current
    Column(modifier = GlanceModifier.fillMaxWidth().padding(vertical = 2.dp).clickable(openAction(context, key, row.link))) {
        Row(modifier = GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            DText(row.title, look, 12, modifier = GlanceModifier.defaultWeight())
            row.amount?.let {
                Spacer(GlanceModifier.width(6.dp))
                DText(it, look, 12, bold = true, align = TextAlign.End)
            }
            row.chip?.let {
                Spacer(GlanceModifier.width(4.dp))
                Chip(it, look)
            }
        }
        if (withSecond) row.second?.let { DText(it, look, 10, muted = true) }
    }
}

@Composable
private fun BarLine(bar: DocBar, look: Look) {
    Column(modifier = GlanceModifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Row(modifier = GlanceModifier.fillMaxWidth()) {
            DText(bar.label, look, 11, modifier = GlanceModifier.defaultWeight())
            bar.value?.let { DText(it, look, 11, bold = true, align = TextAlign.End) }
        }
        LinearProgressIndicator(
            progress = if (bar.label.secret && look.hide) 0f else bar.fraction,
            modifier = GlanceModifier.fillMaxWidth().height(4.dp),
            color = if (bar.tone == Tone.PLAIN && !look.stale) GlanceTheme.colors.primary else toneColor(bar.tone, look),
            backgroundColor = GlanceTheme.colors.surfaceVariant,
        )
    }
}

@Composable
private fun Actions(key: String, actions: List<DocAction>, max: Int, buttonDp: Int, withLabels: Boolean) {
    val context = LocalContext.current
    Row(modifier = GlanceModifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        actions.take(max).forEachIndexed { i, a ->
            if (i > 0) Spacer(GlanceModifier.width(8.dp))
            Column(
                modifier = GlanceModifier.defaultWeight().clickable(openAction(context, key, a.link)),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Box(
                    modifier = GlanceModifier
                        .size(buttonDp.dp)
                        .background(GlanceTheme.colors.primaryContainer)
                        .cornerRadius((buttonDp / 2).dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Image(
                        provider = ImageProvider(iconFor(a.icon)),
                        contentDescription = a.label,
                        modifier = GlanceModifier.size((buttonDp / 2).dp),
                        colorFilter = ColorFilter.tint(GlanceTheme.colors.onPrimaryContainer),
                    )
                }
                if (withLabels) PlainText(a.label, 10, GlanceTheme.colors.onSurface)
            }
        }
    }
}

private fun iconFor(name: String): Int = when (name) {
    "order" -> R.drawable.widget_ic_order
    "collect" -> R.drawable.widget_ic_collect
    "voucher" -> R.drawable.widget_ic_voucher
    "scan" -> R.drawable.widget_ic_scan
    "approve" -> R.drawable.widget_ic_approve
    "notice" -> R.drawable.widget_ic_notice
    else -> R.drawable.widget_ic_open
}

@Composable
private fun EmptyOrMore(text: String) {
    PlainText(text, 11, GlanceTheme.colors.onSurfaceVariant, modifier = GlanceModifier.padding(top = 2.dp))
}

private fun moreText(doc: WidgetDocModel, cut: Int): String? =
    doc.more ?: if (cut > 0) "আরও ${BnTime.digits(cut.toString())}টা" else null

/** 2x1 and 4x1: one line — the hero, else the first row, else the first bar, else the actions. */
@Composable
private fun OneLine(key: String, doc: WidgetDocModel, look: Look, wide: Boolean, root: GlanceModifier) {
    Box(modifier = root, contentAlignment = Alignment.CenterStart) {
        val hero = doc.hero
        val row = doc.rows.firstOrNull()
        val bar = doc.bars.firstOrNull()
        when {
            hero != null -> Row(modifier = GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                PlainText(doc.title, 11, GlanceTheme.colors.onSurfaceVariant, modifier = GlanceModifier.defaultWeight())
                if (look.stale && wide) {
                    Chip(DocText(DocWidget.STALE_CHIP, false, Tone.WARN), Look(false, false))
                    Spacer(GlanceModifier.width(4.dp))
                }
                DText(hero.figure, look, 16, bold = true, align = TextAlign.End)
            }
            row != null -> RowLine(key, row, look, withSecond = false)
            bar != null -> BarLine(bar, look)
            doc.actions.isNotEmpty() -> Actions(key, doc.actions, if (wide) 4 else 2, 36, withLabels = false)
            else -> Row(modifier = GlanceModifier.fillMaxWidth()) {
                PlainText(doc.title, 12, GlanceTheme.colors.onSurface, bold = true, modifier = GlanceModifier.defaultWeight())
                doc.empty?.let { PlainText(it, 11, GlanceTheme.colors.onSurfaceVariant) }
            }
        }
    }
}

/** 2x2 (hero + 2 rows) and 4x2 (hero + 3 rows); bars or actions when there are no rows. */
@Composable
private fun Boxed(key: String, doc: WidgetDocModel, look: Look, maxRows: Int, root: GlanceModifier) {
    Column(modifier = root) {
        Header(doc, look)
        doc.hero?.let { Hero(it, look, 20) }
        when {
            doc.rows.isNotEmpty() -> {
                val fit = doc.rows.take(maxRows)
                fit.forEach { RowLine(key, it, look, withSecond = false) }
                moreText(doc, doc.rows.size - fit.size)?.let { EmptyOrMore(it) }
            }
            doc.bars.isNotEmpty() -> doc.bars.take(if (doc.hero == null) maxRows else maxRows - 1).forEach { BarLine(it, look) }
            doc.actions.isNotEmpty() && doc.hero == null -> Actions(key, doc.actions, if (maxRows >= 3) 4 else 2, 40, withLabels = maxRows >= 3)
            else -> doc.empty?.let { EmptyOrMore(it) }
        }
    }
}

/** 4x6: everything — hero, up to 8 rows with their second line, bars, actions. */
@Composable
private fun Tall(key: String, doc: WidgetDocModel, look: Look, root: GlanceModifier) {
    Column(modifier = root) {
        Header(doc, look)
        Spacer(GlanceModifier.height(4.dp))
        doc.hero?.let { Hero(it, look, 26) }
        val fit = doc.rows.take(8)
        fit.forEach { RowLine(key, it, look, withSecond = true) }
        if (doc.rows.isEmpty()) doc.empty?.let { EmptyOrMore(it) }
        moreText(doc, doc.rows.size - fit.size)?.let { EmptyOrMore(it) }
        if (doc.bars.isNotEmpty()) {
            Spacer(GlanceModifier.height(4.dp))
            doc.bars.take(4).forEach { BarLine(it, look) }
        }
        if (doc.actions.isNotEmpty()) {
            Spacer(GlanceModifier.defaultWeight())
            Actions(key, doc.actions, 4, 48, withLabels = true)
        }
    }
}
