package com.abos.abos_mobile.widgets

import android.content.Context
import android.content.SharedPreferences
import com.abos.abos_mobile.R

/**
 * ⭐ The few words the widgets draw themselves, in the language the person
 * chose in the app (0.4.43, cb, 21 Oct 2026).
 *
 * Everything else on a widget — titles, rows, figures, "আরও 3টা" — is written
 * by Dart from the ARB files and arrives inside the widget's JSON already in
 * the right language. What is left for Kotlin is what it draws without a
 * document or around one: the placeholder before the first sync, the "old"
 * chip, the time's "yesterday", the "N more" when a size cuts rows, and the
 * fixed labels of the two older RemoteViews widgets.
 *
 * One source for those: res/values/widget_words.xml, each word as a pair
 * (name = Bangla, name_en = English). [english] reads the home_widget value
 * `widget_locale` that Dart writes (WidgetHub.localeKey): "en" is English;
 * anything else, or nothing at all (the app never ran), is Bangla — the
 * app's own default. There is no values-en folder on purpose: Android picks
 * that by the phone's language, and every English phone would then see
 * English widget labels while the app itself is still Bangla.
 */
class Word(private val bn: Int, private val en: Int) {
    fun get(context: Context, english: Boolean): String = context.getString(if (english) en else bn)
}

object WidgetWords {
    /** home_widget value Dart writes — "bn" or "en" (WidgetHub.localeKey). */
    const val LOCALE = "widget_locale"

    fun english(prefs: SharedPreferences?): Boolean = prefs?.getString(LOCALE, null) == "en"

    val PLACEHOLDER = Word(R.string.widget_word_placeholder, R.string.widget_word_placeholder_en)
    val STALE = Word(R.string.widget_word_stale, R.string.widget_word_stale_en)
    val YESTERDAY = Word(R.string.widget_word_yesterday, R.string.widget_word_yesterday_en)

    /** "আরও %1$sটা" / "%1$s more" */
    val MORE = Word(R.string.widget_word_more, R.string.widget_word_more_en)

    /** Each kit widget's name, drawn before its first sync — the same word the picker shows (strings.xml points here). */
    val TITLES: Map<String, Word> = mapOf(
        "sales_today" to Word(R.string.widget_sales_today_title, R.string.widget_sales_today_title_en),
        "money_in_hand" to Word(R.string.widget_money_in_hand_title, R.string.widget_money_in_hand_title_en),
        "approvals" to Word(R.string.widget_approvals_title, R.string.widget_approvals_title_en),
        "big_dues" to Word(R.string.widget_big_dues_title, R.string.widget_big_dues_title_en),
        "stock_alerts" to Word(R.string.widget_stock_alerts_title, R.string.widget_stock_alerts_title_en),
        "challans_today" to Word(R.string.widget_challans_today_title, R.string.widget_challans_today_title_en),
        "notices" to Word(R.string.widget_notices_title, R.string.widget_notices_title_en),
        "quick_actions" to Word(R.string.widget_quick_actions_title, R.string.widget_quick_actions_title_en),
        "target" to Word(R.string.widget_target_title, R.string.widget_target_title_en),
        "sync_state" to Word(R.string.widget_sync_state_title, R.string.widget_sync_state_title_en),
        "collections_today" to Word(R.string.widget_collections_today_title, R.string.widget_collections_today_title_en),
        "credit_limits" to Word(R.string.widget_credit_limits_title, R.string.widget_credit_limits_title_en),
        "cheques" to Word(R.string.widget_cheques_title, R.string.widget_cheques_title_en),
        "sr_board" to Word(R.string.widget_sr_board_title, R.string.widget_sr_board_title_en),
        "goods_incoming" to Word(R.string.widget_goods_incoming_title, R.string.widget_goods_incoming_title_en),
        "expenses" to Word(R.string.widget_expenses_title, R.string.widget_expenses_title_en),
        "profit" to Word(R.string.widget_profit_title, R.string.widget_profit_title_en),
        "offers" to Word(R.string.widget_offers_title, R.string.widget_offers_title_en),
        "attendance" to Word(R.string.widget_attendance_title, R.string.widget_attendance_title_en),
        "bank_reconcile" to Word(R.string.widget_bank_reconcile_title, R.string.widget_bank_reconcile_title_en),
        "principal_commission" to Word(R.string.widget_principal_commission_title, R.string.widget_principal_commission_title_en),
        "owner_board" to Word(R.string.widget_owner_board_title, R.string.widget_owner_board_title_en),
    )

    /** The older figures widget's fixed labels: view id → word. */
    val TODAY_LABELS: List<Pair<Int, Word>> = listOf(
        R.id.label_widget_col_today to Word(R.string.widget_col_today, R.string.widget_col_today_en),
        R.id.label_widget_col_month to Word(R.string.widget_col_month, R.string.widget_col_month_en),
        R.id.label_widget_col_change to Word(R.string.widget_col_change, R.string.widget_col_change_en),
        R.id.label_widget_row_sales to Word(R.string.widget_row_sales, R.string.widget_row_sales_en),
        R.id.label_widget_row_expense to Word(R.string.widget_row_expense, R.string.widget_row_expense_en),
        R.id.label_widget_row_purchase to Word(R.string.widget_row_purchase, R.string.widget_row_purchase_en),
        R.id.label_widget_row_inflow to Word(R.string.widget_row_inflow, R.string.widget_row_inflow_en),
        R.id.label_widget_cash to Word(R.string.widget_cash, R.string.widget_cash_en),
        R.id.label_widget_mfs to Word(R.string.widget_mfs, R.string.widget_mfs_en),
        R.id.label_widget_bank to Word(R.string.widget_bank, R.string.widget_bank_en),
        R.id.label_widget_in_transit to Word(R.string.widget_in_transit, R.string.widget_in_transit_en),
        R.id.label_widget_total_money to Word(R.string.widget_total_money, R.string.widget_total_money_en),
        R.id.label_widget_receivable to Word(R.string.widget_receivable, R.string.widget_receivable_en),
        R.id.label_widget_payable to Word(R.string.widget_payable, R.string.widget_payable_en),
        R.id.label_widget_stock_value to Word(R.string.widget_stock_value, R.string.widget_stock_value_en),
    )

    /** The older approvals widget's heading. */
    val APPROVALS_TITLE = Word(R.string.approvals_title, R.string.approvals_title_en)
}

/** The renderer's own words for one draw, already in the person's language. */
class Words(
    val english: Boolean,
    val placeholder: String,
    val stale: String,
    val yesterday: String,
    private val morePattern: String,
    val title: String?,
) {
    /** "আরও ৩টা" / "3 more" — the count in the language's digits */
    fun more(count: Int): String = String.format(java.util.Locale.ROOT, morePattern, BnTime.digits(count.toString(), english))

    companion object {
        fun of(context: Context, key: String, english: Boolean) = Words(
            english = english,
            placeholder = WidgetWords.PLACEHOLDER.get(context, english),
            stale = WidgetWords.STALE.get(context, english),
            yesterday = WidgetWords.YESTERDAY.get(context, english),
            morePattern = WidgetWords.MORE.get(context, english),
            title = WidgetWords.TITLES[key]?.get(context, english),
        )
    }
}
