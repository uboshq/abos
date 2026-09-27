package com.abos.abos_mobile

import android.appwidget.AppWidgetManager
import android.content.Context
import android.content.SharedPreferences
import android.net.Uri
import android.widget.RemoteViews
import es.antonborri.home_widget.HomeWidgetLaunchIntent
import es.antonborri.home_widget.HomeWidgetProvider

/**
 * The day's money on the home screen.
 *
 * The launcher draws this, not Flutter: a widget lives in the launcher's
 * process and nothing in Dart can render into it. So the app writes the
 * figures into shared preferences when it has them, and this reads them back
 * and fills in a RemoteViews. Two processes, one small set of strings between
 * them.
 *
 * It formats nothing. Every value is written by Dart already formatted, taka
 * sign and grouping included, because that rule lives in one place in this
 * codebase (Money.taka) and it is not here.
 *
 * And it never fetches. A widget that made its own network call would need
 * its own auth, its own token refresh and its own idea of which company is
 * signed in: three things the app already owns and would then own twice. It
 * shows the last figures the app saw, with the time the server said they were
 * true.
 *
 * A cell the app has never written shows a dash, not a zero. Zero is a fact
 * about the business; a dash says nothing is known. See docs/Contract §৮
 * rule ক, which is the same rule one process further out.
 */
class TodayWidgetProvider : HomeWidgetProvider() {

    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        widgetData: SharedPreferences
    ) {
        appWidgetIds.forEach { widgetId ->
            val views = RemoteViews(context.packageName, R.layout.today_widget).apply {
                CELLS.forEach { (viewId, key) ->
                    setTextViewText(viewId, widgetData.getString(key, DASH))
                }
                setTextViewText(R.id.widget_company, widgetData.getString(KEY_COMPANY, ""))
                setTextViewText(R.id.widget_asof, widgetData.getString(KEY_AS_OF, ""))

                // The whole widget opens the day's page, not just one number.
                // Opening the app refreshes these figures on the way, so the
                // refresh is had for free by the gesture people were going to
                // make anyway.
                setOnClickPendingIntent(
                    R.id.widget_root,
                    HomeWidgetLaunchIntent.getActivity(
                        context,
                        MainActivity::class.java,
                        Uri.parse("abos://widget/today")
                    )
                )
            }
            appWidgetManager.updateAppWidget(widgetId, views)
        }
    }

    companion object {
        /* The names Dart writes under (today_launcher_widget.dart). Duplicated
           across a process boundary, so they are constants on both sides: a
           typo here shows up as a dash on a home screen and nowhere else. */
        const val KEY_SALES_TODAY = "widget_sales_today"
        const val KEY_SALES_MONTH = "widget_sales_month"
        const val KEY_SALES_CHANGE = "widget_sales_change"
        const val KEY_EXPENSE_TODAY = "widget_expense_today"
        const val KEY_EXPENSE_MONTH = "widget_expense_month"
        const val KEY_EXPENSE_CHANGE = "widget_expense_change"
        const val KEY_PURCHASE_TODAY = "widget_purchase_today"
        const val KEY_PURCHASE_MONTH = "widget_purchase_month"
        const val KEY_PURCHASE_CHANGE = "widget_purchase_change"
        const val KEY_INFLOW_TODAY = "widget_inflow_today"
        const val KEY_INFLOW_MONTH = "widget_inflow_month"
        const val KEY_INFLOW_CHANGE = "widget_inflow_change"
        const val KEY_CASH = "widget_cash"
        const val KEY_MFS = "widget_mfs"
        const val KEY_BANK = "widget_bank"
        const val KEY_IN_TRANSIT = "widget_in_transit"
        const val KEY_TOTAL_MONEY = "widget_total_money"
        const val KEY_RECEIVABLE = "widget_receivable"
        const val KEY_PAYABLE = "widget_payable"
        const val KEY_STOCK = "widget_stock"
        const val KEY_COMPANY = "widget_company"
        const val KEY_AS_OF = "widget_as_of"

        private val CELLS = listOf(
            R.id.widget_sales_today to KEY_SALES_TODAY,
            R.id.widget_sales_month to KEY_SALES_MONTH,
            R.id.widget_sales_change to KEY_SALES_CHANGE,
            R.id.widget_expense_today to KEY_EXPENSE_TODAY,
            R.id.widget_expense_month to KEY_EXPENSE_MONTH,
            R.id.widget_expense_change to KEY_EXPENSE_CHANGE,
            R.id.widget_purchase_today to KEY_PURCHASE_TODAY,
            R.id.widget_purchase_month to KEY_PURCHASE_MONTH,
            R.id.widget_purchase_change to KEY_PURCHASE_CHANGE,
            R.id.widget_inflow_today to KEY_INFLOW_TODAY,
            R.id.widget_inflow_month to KEY_INFLOW_MONTH,
            R.id.widget_inflow_change to KEY_INFLOW_CHANGE,
            R.id.widget_cash to KEY_CASH,
            R.id.widget_mfs to KEY_MFS,
            R.id.widget_bank to KEY_BANK,
            R.id.widget_in_transit to KEY_IN_TRANSIT,
            R.id.widget_total_money to KEY_TOTAL_MONEY,
            R.id.widget_receivable to KEY_RECEIVABLE,
            R.id.widget_payable to KEY_PAYABLE,
            R.id.widget_stock to KEY_STOCK
        )

        /** Before the app has ever run, or for a figure the server does not
         *  send. Not "0": nothing is known, and a zero would be read as a
         *  fact about the business. */
        private const val DASH = "—"
    }
}
