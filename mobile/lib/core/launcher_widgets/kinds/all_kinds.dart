import '../kit/widget_kind.dart';
import 'bank_reconcile.dart';
import 'cheques.dart';
import 'credit_limits.dart';
import 'expenses.dart';
import 'money_in_hand.dart';
import 'owner_board.dart';
import 'profit.dart';

/// ⭐ হোম-স্ক্রিনের সব উইজেটের চাবি — এই ক্রমেই (পরিকল্পনা ৪, অংশ ৯; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
///
/// <p>⛔ প্রতিটা চাবি তিন জায়গায় হুবহু এক: সার্ভারের `GET /api/v1/widgets/summary` → `widgets.<key>`, ফোনের ভাণ্ডারে
/// `doc_<key>`, আর Android-এর `com.abos.abos_mobile.widgets.<CamelKey>WidgetReceiver` (`sales_today` → `SalesToday`)।
/// সাইন-আউটে [WidgetHub.clear] এই তালিকা ধরেই সব মোছে — তাই কোনো উইজেট [allKinds]-এ থাকলে এখানেও থাকতেই হবে (টেস্ট মেলায়)।
const List<String> widgetKeys = [
  'sales_today',
  'money_in_hand',
  'approvals',
  'big_dues',
  'stock_alerts',
  'challans_today',
  'notices',
  'quick_actions',
  'target',
  'sync_state',
  'collections_today',
  'credit_limits',
  'cheques',
  'sr_board',
  'goods_incoming',
  'expenses',
  'profit',
  'offers',
  'attendance',
  'bank_reconcile',
  'owner_board',
];

/// ⭐ লেখা হয়ে যাওয়া উইজেটগুলো — [WidgetHub.refresh] এই তালিকাই ভরে।
///
/// <p>ⓘ দলের প্রত্যেকে নিজের শাখায় নিজের উইজেট লেখেন (`kinds/<key>_kind.dart`), তারপর এখানে **এক লাইন** যোগ করেন —
/// উপরের [widgetKeys]-এর ক্রমে, যাতে মেলানোর সময় দুজনের লাইন একই জায়গায় না পড়ে:
///
/// ```dart
/// // SalesTodayKind(),      // sales_today
/// // MoneyInHandKind(),     // money_in_hand
/// // ...
/// // OwnerBoardKind(),      // owner_board — লিড লেখেন
/// ```
///
/// <p>⛔ তালিকায় না থাকা উইজেট ফোনে কখনো চালু হয় না (রিসিভার বন্ধ থাকে) — "লেখা হয়নি" আর "অনুমতি নেই" একই ফল।
final List<LauncherWidgetKind> allKinds = <LauncherWidgetKind>[
  // ⓘ এখানে এক লাইন করে — চাবির ক্রমে (sales_today … owner_board)
  const MoneyInHandWidget(), // money_in_hand — cb
  const CreditLimitsWidget(), // credit_limits — cb
  const ChequesWidget(), // cheques — cb
  const ExpensesWidget(), // expenses — cb
  const ProfitWidget(), // profit — cb
  const BankReconcileWidget(), // bank_reconcile — cb
  const OwnerBoardWidget(),
];
