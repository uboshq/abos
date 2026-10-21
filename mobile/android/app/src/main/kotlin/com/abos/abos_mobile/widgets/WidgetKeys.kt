package com.abos.abos_mobile.widgets

/**
 * ⭐ The 22 home-screen widgets (principal_commission added by cb, 20 Oct 2026) of the widget kit (20 Oct 2026), one receiver each.
 *
 * Every key is one widget the person can place. Dart writes the widget's
 * WidgetDoc under `doc_<key>` (lib/core/launcher_widgets/kit/widget_doc.dart),
 * then asks home_widget to update `com.abos.abos_mobile.widgets.<Camel>WidgetReceiver`.
 *
 * The order, the keys and the class names are a contract with Dart:
 * the MethodChannel's keys() returns [ALL] so the Dart side can check that its
 * own list matches, and a key that is in one list only shows up there, not as
 * a blank tile on somebody's home screen.
 */
object WidgetKeys {

    /** key → the receiver that draws it. Insertion order is the kit's order. */
    val RECEIVERS: Map<String, Class<out DocWidgetReceiver>> = linkedMapOf(
        "sales_today" to SalesTodayWidgetReceiver::class.java,
        "money_in_hand" to MoneyInHandWidgetReceiver::class.java,
        "approvals" to ApprovalsWidgetReceiver::class.java,
        "big_dues" to BigDuesWidgetReceiver::class.java,
        "stock_alerts" to StockAlertsWidgetReceiver::class.java,
        "challans_today" to ChallansTodayWidgetReceiver::class.java,
        "notices" to NoticesWidgetReceiver::class.java,
        "quick_actions" to QuickActionsWidgetReceiver::class.java,
        "target" to TargetWidgetReceiver::class.java,
        "sync_state" to SyncStateWidgetReceiver::class.java,
        "collections_today" to CollectionsTodayWidgetReceiver::class.java,
        "credit_limits" to CreditLimitsWidgetReceiver::class.java,
        "cheques" to ChequesWidgetReceiver::class.java,
        "sr_board" to SrBoardWidgetReceiver::class.java,
        "goods_incoming" to GoodsIncomingWidgetReceiver::class.java,
        "expenses" to ExpensesWidgetReceiver::class.java,
        "profit" to ProfitWidgetReceiver::class.java,
        "offers" to OffersWidgetReceiver::class.java,
        "attendance" to AttendanceWidgetReceiver::class.java,
        "bank_reconcile" to BankReconcileWidgetReceiver::class.java,
        "principal_commission" to PrincipalCommissionWidgetReceiver::class.java,
        "owner_board" to OwnerBoardWidgetReceiver::class.java,
    )

    val ALL: List<String> = RECEIVERS.keys.toList()

    /** The picker's name for each widget — the same words as res/values/strings.xml (widget_<key>_name). */
    val LABELS: Map<String, String> = mapOf(
        "sales_today" to "আজকের বিক্রি",
        "money_in_hand" to "টাকা হাতে",
        "approvals" to "সইয়ের অপেক্ষা",
        "big_dues" to "বড় বকেয়া",
        "stock_alerts" to "মজুদ সতর্কতা",
        "challans_today" to "আজকের চালান",
        "notices" to "নোটিশ",
        "quick_actions" to "দ্রুত কাজ",
        "target" to "লক্ষ্য বনাম অর্জন",
        "sync_state" to "সিঙ্কের অবস্থা",
        "collections_today" to "আজকের আদায়",
        "credit_limits" to "বাকির সীমা",
        "cheques" to "চেক",
        "sr_board" to "SR তালিকা",
        "goods_incoming" to "মাল আসছে",
        "expenses" to "খরচ",
        "profit" to "লাভ",
        "offers" to "অফার",
        "attendance" to "উপস্থিতি",
        "bank_reconcile" to "ব্যাংক মেলানো",
        "principal_commission" to "প্রিন্সিপালের কমিশন",
        "owner_board" to "মালিকের পূর্ণ পর্দা",
    )

    /** SharedPreferences (home_widget's own store, HomeWidgetPlugin.getData) — what Dart writes. */
    const val DOC_PREFIX = "doc_"
    const val AMOUNTS_HIDDEN = "widget_amounts_hidden"

    /** মানুষের আলো/অন্ধকার বাছাই — "phone" (ফোনের মতো), "light", "dark" (অ্যাপের ThemeChoices) */
    const val THEME = "widget_theme"
}
