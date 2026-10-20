package com.abos.abos_mobile.widgets

import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver

/**
 * One receiver per widget key, all drawing with the same [DocWidget].
 *
 * Android names a widget by its receiver class: the picker lists receivers,
 * the manifest enables or disables them, and home_widget's updateWidget sends
 * its broadcast to one. So 21 widgets need 21 classes, but each is one line.
 *
 * ⛔ Every one is declared android:enabled="false" in the manifest; the Dart
 * side switches on only the widgets the signed-in person may see
 * (MethodChannel abos/launcher_widgets, setEnabled).
 */
abstract class DocWidgetReceiver(val key: String) : GlanceAppWidgetReceiver() {

    override val glanceAppWidget: GlanceAppWidget = DocWidget(key)

    override fun onReceive(context: Context, intent: Intent) {
        /* A courtesy tile must never be able to close the app: this runs in the
           app's own process (0.4.1). */
        try {
            // Process alive → listen for lock/unlock, so secret texts follow the lock.
            LockWatch.ensure(context.applicationContext)
            super.onReceive(context, intent)
        } catch (t: Throwable) {
            Log.e("DocWidgetReceiver", "widget $key update failed", t)
        }
    }
}

class SalesTodayWidgetReceiver : DocWidgetReceiver("sales_today")
class MoneyInHandWidgetReceiver : DocWidgetReceiver("money_in_hand")
class ApprovalsWidgetReceiver : DocWidgetReceiver("approvals")
class BigDuesWidgetReceiver : DocWidgetReceiver("big_dues")
class StockAlertsWidgetReceiver : DocWidgetReceiver("stock_alerts")
class ChallansTodayWidgetReceiver : DocWidgetReceiver("challans_today")
class NoticesWidgetReceiver : DocWidgetReceiver("notices")
class QuickActionsWidgetReceiver : DocWidgetReceiver("quick_actions")
class TargetWidgetReceiver : DocWidgetReceiver("target")
class SyncStateWidgetReceiver : DocWidgetReceiver("sync_state")
class CollectionsTodayWidgetReceiver : DocWidgetReceiver("collections_today")
class CreditLimitsWidgetReceiver : DocWidgetReceiver("credit_limits")
class ChequesWidgetReceiver : DocWidgetReceiver("cheques")
class SrBoardWidgetReceiver : DocWidgetReceiver("sr_board")
class GoodsIncomingWidgetReceiver : DocWidgetReceiver("goods_incoming")
class ExpensesWidgetReceiver : DocWidgetReceiver("expenses")
class ProfitWidgetReceiver : DocWidgetReceiver("profit")
class OffersWidgetReceiver : DocWidgetReceiver("offers")
class AttendanceWidgetReceiver : DocWidgetReceiver("attendance")
class BankReconcileWidgetReceiver : DocWidgetReceiver("bank_reconcile")
class PrincipalCommissionWidgetReceiver : DocWidgetReceiver("principal_commission")
class OwnerBoardWidgetReceiver : DocWidgetReceiver("owner_board")
