package com.abos.abos_mobile

import android.appwidget.AppWidgetManager
import android.content.Context
import android.content.SharedPreferences
import android.net.Uri
import android.util.Log
import android.view.View
import android.widget.RemoteViews
import es.antonborri.home_widget.HomeWidgetProvider

/**
 * What is waiting for a signature, on the home screen. Two cells by two.
 *
 * A second widget rather than more rows on the first, because the two answer
 * different questions. TodayWidgetProvider answers "how is the business" and
 * is read at a glance; this answers "is anything waiting for me", which is a
 * queue, and a queue with nothing in it is itself the answer.
 *
 * Same division of labour as the other one: Dart fetches, formats and writes
 * strings; this reads them back and fills a RemoteViews. No arithmetic, no
 * formatting, no network.
 *
 * RemoteViews cannot loop, so three rows are drawn in the layout and hidden
 * individually. Three is what fits two cells of height under the count; the
 * count is what can be read from across a room, and KEY_MORE says so when the
 * queue is longer than the rows.
 */
class ApprovalsWidgetProvider : HomeWidgetProvider() {

    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        widgetData: SharedPreferences
    ) {
        /* A courtesy tile must never be able to close the app: this runs in the
           app's own process, so an exception here ended it (0.4.1). */
        try {
            appWidgetIds.forEach { widgetId ->
                val views = RemoteViews(context.packageName, R.layout.approvals_widget).apply {
                    val count = widgetData.getString(KEY_COUNT, DASH)
                    setTextViewText(R.id.approvals_count, count)

                    var shown = 0
                    ROWS.forEach { (viewId, key) ->
                        val text = widgetData.getString(key, null)
                        if (text.isNullOrEmpty()) {
                            setViewVisibility(viewId, View.GONE)
                        } else {
                            setTextViewText(viewId, text)
                            setViewVisibility(viewId, View.VISIBLE)
                            shown++
                        }
                    }

                    // "Nothing waiting" is a sentence somebody can act on. Three
                    // empty lines are a widget that looks broken.
                    //
                    // Only when the app has actually written a count: before the
                    // first sign-in there is no news either way, and claiming an
                    // empty queue would be a claim about a company nobody has
                    // signed into yet.
                    val emptyText = widgetData.getString(KEY_EMPTY, null)
                    val emptyVisible = shown == 0 && count != DASH && !emptyText.isNullOrEmpty()
                    setTextViewText(R.id.approvals_empty, emptyText ?: "")
                    setViewVisibility(R.id.approvals_empty, if (emptyVisible) View.VISIBLE else View.GONE)

                    val more = widgetData.getString(KEY_MORE, "")
                    setTextViewText(R.id.approvals_more, more)
                    setViewVisibility(
                        R.id.approvals_more,
                        if (more.isNullOrEmpty()) View.GONE else View.VISIBLE
                    )

                    setTextViewText(R.id.approvals_asof, widgetData.getString(KEY_AS_OF, ""))

                    // Straight to the inbox: the person tapping a queue wants the
                    // queue, not the home tab with a tile for it.
                    setOnClickPendingIntent(
                        R.id.approvals_root,
                        WidgetLaunch.open(context, Uri.parse("abos://widget/approvals"))
                    )
                }
                appWidgetManager.updateAppWidget(widgetId, views)
            }
        } catch (t: Throwable) {
            Log.e("ApprovalsWidgetProvider", "widget update failed", t)
        }
    }

    companion object {
        /* The names Dart writes under (approvals_launcher_widget.dart).
           Constants on both sides of the process boundary, because a typo
           here shows up as a blank line on a home screen and nowhere else. */
        const val KEY_COUNT = "approvals_count"
        const val KEY_ROW_1 = "approvals_row_1"
        const val KEY_ROW_2 = "approvals_row_2"
        const val KEY_ROW_3 = "approvals_row_3"
        const val KEY_EMPTY = "approvals_empty"
        const val KEY_MORE = "approvals_more"
        const val KEY_AS_OF = "approvals_as_of"

        private val ROWS = listOf(
            R.id.approvals_row_1 to KEY_ROW_1,
            R.id.approvals_row_2 to KEY_ROW_2,
            R.id.approvals_row_3 to KEY_ROW_3
        )

        /** Before the app has ever run. Not "0": nothing is known yet, and a
         *  zero here would claim an empty queue. */
        private const val DASH = "—"
    }
}
