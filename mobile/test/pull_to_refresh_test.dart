import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/report_record.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/attendance/attendance_screen.dart';
import 'package:abos_mobile/features/copilot/copilot_screen.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/core/records/notification_record.dart';
import 'package:abos_mobile/features/notifications/notifications_screen.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';
import 'package:abos_mobile/features/products/product_list_screen.dart';
import 'package:abos_mobile/features/reports/report_by_key_screen.dart';
import 'package:abos_mobile/features/reports/reports_screen.dart';
import 'package:abos_mobile/features/stock/stock_list_screen.dart';

/// The pull that does nothing — a bug found on a device twice.
///
/// <p>On a phone with nothing synced yet, a screen draws its "নিচে টেনে আবার
/// চেষ্টা করুন" message. A [RefreshIndicator] recognises a pull only on a
/// **scrollable descendant**, so an [EmptyState] rendered outside one leaves
/// the single instruction on screen doing nothing when followed. Found in
/// September on three list screens, fixed, and covered by a test the same day.
///
/// <p>⛔ <b>That test named those three screens by hand.</b> On 16 September
/// the identical bug turned up on a fourth — the orders screen — and was found
/// the identical way: by pulling on a real screen and watching nothing happen.
/// The suite had been green throughout. A hand-written list cannot notice a
/// screen nobody put on it, so its greenness meant only that those three
/// screens were still fine.
///
/// <p>So this file counts instead of listing. The two sets below are checked
/// against the files that actually use [EmptyState], and a new screen that can
/// draw one fails this suite until somebody says which set it belongs in.
void main() {
  /// Screens whose empty state must be pull-to-refresh, each verified below.
  const pullable = {
    // ⭐ cb — প্রিন্সিপালের কমিশন (২০ অক্টোবর ২০২৬); টান পরীক্ষা principal_commission_screen_test-এ
    'principal_commission_screen.dart',
    // ⭐ a5 — মালিকের কেন্দ্রের পাতা আর বিক্রয়ের পরিকল্পনা (১৯ অক্টোবর ২০২৬); টান পরীক্ষা owner_pages_test আর sales_plan_test-এ
    'owner_compare_screen.dart',
    'owner_history_screen.dart',
    'sister_links_screen.dart',
    'sales_plan_screens.dart',
    'customer_list_screen.dart',
    'product_list_screen.dart',
    'stock_list_screen.dart',
    'order_list_screen.dart',
    // ⭐ ডিও/আদেশের তালিকা আর ডেলিভারি ট্র্যাকিং — নকশা ৩-এর খালি অবস্থা (a5, ১৯ অক্টোবর ২০২৬); টান test/a5_orders_do_design3_test.dart-এ দেখা
    'delivery_order_screens.dart',
    'delivery_tracking_screen.dart',
    'due_list_screen.dart',
    // ⭐ মাথার ঘণ্টার পাতা — মালিক, ৬ অক্টোবর ২০২৬
    'notifications_screen.dart',
    'approval_inbox_screen.dart',
    'attendance_screen.dart',
    'reports_screen.dart',
    // ⭐ মেনুর রিপোর্ট-সারি, চাবি ধরে — চাবি না মিললে বা না এলে টেনে আবার (a6, ১৯ অক্টোবর ২০২৬)
    'report_by_key_screen.dart',
    'dashboards_screen.dart',
    'my_route_screen.dart',
    'lead_screens.dart',
    'quotation_screens.dart',
    'sync_status_screen.dart',
    // ⭐ নিজের ছুটি আর বেতনের স্লিপ — ধরা পড়ে test/more_modules_pullable_test.dart-এ (১৩ অক্টোবর ২০২৬)
    'self_service_screens.dart',
    // ⭐ ১৩ অক্টোবর ২০২৬ — মালিকের কেন্দ্র, বাকি নিয়ন্ত্রণ, নোটিশ বোর্ড; প্রত্যেকটার টান নিজের টেস্টে দেখা
    'executive_screen.dart',
    'credit_screens.dart',
    'notice_board_screen.dart',
    // ⭐ বিক্রয় বিল আর চালান (১৩ অক্টোবর ২০২৬) — টান নিজের টেস্টে দেখা
    'sales_paper_screens.dart',
    // ⭐ হিসাবের ছক আর খাতার খাতা (১৭ অক্টোবর ২০২৬) — টান test/account_ledger_test.dart-এ দেখা
    'chart_screens.dart',
    // ⭐ সইয়ের ভার, দলিলের অনুমোদনের সারি, সই কেন্দ্র (১৭ অক্টোবর ২০২৬) — টান test/approval_rows_test.dart-এ দেখা
    'approval_rows_screens.dart',
    // ⭐ বিক্রয়ের ডেস্ক-পাতা আর দলিলের ছাঁচ (১৭ অক্টোবর ২০২৬) — টান test/sales_desk_test.dart-এ দেখা
    'sales_desk_screens.dart',
    // ⭐ ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর (১৭ অক্টোবর ২০২৬) — টান test/papers_test.dart-এ দেখা
    'paper_screens.dart',
    // ⭐ হাজিরা আর ছুটির রেজিস্টার (১৭ অক্টোবর ২০২৬) — টান test/hr_register_test.dart-এ দেখা
    'hr_register_screens.dart',
    // ⭐ কাগজ তোলা আর স্ক্যান (১৭ অক্টোবর ২০২৬) — ফর্ম আনা না গেলে টানলে আবার; দেখা test/document_upload_test.dart-এ
    'document_upload_screen.dart',
    // ⭐ মজুদের পাঁচ লেখা (১৭ অক্টোবর ২০২৬) — টান test/stock_writes_test.dart-এ দেখা
    'stock_write_screens.dart',
    // ⭐ উদ্ধৃতির তুলনা ও সংস্করণ, মান যাচাই আর সিরিয়াল (১৭ অক্টোবর ২০২৬) — টান test/more_screens_test.dart-এ দেখা
    'quotation_compare_screens.dart',
    'quality_serial_screens.dart',
    // ⭐ টাকার হাতবদল, নগদ গোনা, অর্থের পাতা (১৭ অক্টোবর ২০২৬) — টান test/money_screens_test.dart-এ দেখা
    'money_screens.dart',
    // ⭐ আমার অনুরোধ (১৭ অক্টোবর ২০২৬) — টান test/my_requests_test.dart-এ দেখা
    'my_requests_screen.dart',
    // ⭐ আমার ফোনগুলো (০.৪.৪১, a6, ২০ অক্টোবর ২০২৬) — টান test/profile/my_phones_screen_test.dart-এ দেখা
    'my_phones_screen.dart',
    // ⭐ টাকা আদায়ের খালি তালিকা (নকশা ৩, ২০ অক্টোবর ২০২৬) — টান test/a5_counter_money_dues_design3_test.dart-এ দেখা
    'money_in_screens.dart',
    // ⭐ কোপাইলট (০.৪.৫৪, ১১ নভেম্বর ২০২৬) — আনতে না পারলে খালি অবস্থা; টান নিচে এই ফাইলেই দেখা
    'copilot_screen.dart',
  };

  /// Screens whose empty state is deliberately **not** pull-to-refresh, each
  /// with its reason. Being here is a decision somebody made, not an oversight
  /// — which is the whole difference this file is trying to keep.
  const notPullable = {
    // The menu is built from GET /me at sign-in; there is nothing on this
    // screen for a pull to fetch, and its message sends the person to the
    // office rather than to a gesture. (ⓘ 12 Oct 2026: the "no menu" state
    // moved with the module grid from home_shell.dart to module_pages.dart.)
    'module_pages.dart',
    // A paper reopened from "সাম্প্রতিক" whose kind this build does not know —
    // a pull would fetch nothing; the message says to update the app.
    'paper_route.dart',
    // Two empty states, neither refreshable here: the catalogue one sends the
    // person to the products screen to pull *there*, and the other is a
    // search box reporting no match, where a pull would fetch nothing the
    // typing did not already decide.
    'new_order_screen.dart',
    // ⭐ অ্যাপের ভিতরের ওয়েব-জানালা (১৩ অক্টোবর ২০২৬) — ভুলের পর্দায় নিজের "আবার চেষ্টা করুন" বোতাম, কারণ
    // সেখানে টান দিয়ে চাওয়ার মতো কোনো তালিকা নেই, আছে একবারের চাবি; আর খোলা পাতার টান পাতার ভিতরেই ([WebFiles]).
    'web_inside_screen.dart',
    // ⭐ "শীঘ্রই আসছে" (১৭ অক্টোবর ২০২৬) — এখনো তৈরি হয়নি এমন পর্দা; টেনে আনার মতো কিছুই নেই, কথাটা কেবল জানানো।
    'coming_soon_screen.dart',
    // ⭐ নকশা ৩ (২০ অক্টোবর ২০২৬): কাউন্টারের খসড়ার তালিকা নিচের পাতায় (bottom sheet) — টানলে পাতাটাই বন্ধ হয়, তাই খালিতে
    // নিজের "নতুন বিল লিখুন" আর ভুলে "আবার চেষ্টা করুন"; ফেরতের পর্দা একটা ফর্ম — বিল না থাকলে নিজের "নতুন করে দেখুন" বোতাম।
    // দুটোই দেখা test/a5_counter_money_dues_design3_test.dart-এ।
    'counter_screen.dart',
    'return_screen.dart',
  };

  test('every screen that can draw an EmptyState has been considered', () {
    final dir = Directory('lib/features');
    expect(dir.existsSync(), isTrue,
        reason: 'run from the package root, the way flutter test does');

    final using = <String>{};
    for (final entity in dir.listSync(recursive: true)) {
      if (entity is! File || !entity.path.endsWith('.dart')) continue;
      if (!entity.readAsStringSync().contains('EmptyState(')) continue;
      using.add(entity.uri.pathSegments.last);
    }

    // A scan that finds nothing checks nothing, and would pass forever.
    expect(using.length, greaterThan(5),
        reason: 'the scan of lib/features found almost no EmptyState, which '
            'means it is looking in the wrong place');

    // ⛔ The assertion that would have caught the orders screen in September.
    expect(using.difference(pullable).difference(notPullable), isEmpty,
        reason: 'a screen draws an EmptyState and nobody has said whether '
            'pulling on it should refresh. Put it in one of the two sets in '
            'this file — and if it is pullable, wrap the EmptyState in a '
            'RefreshIndicator over a scrollable, then add it below');

    // And the other direction: a set naming a screen that no longer exists is
    // a test quietly guarding nothing.
    expect(pullable.union(notPullable).difference(using), isEmpty,
        reason: 'these are listed here but no longer draw an EmptyState');
  });

  Future<void> expectPullable(WidgetTester tester, Widget screen) async {
    await tester.pumpWidget(MaterialApp(home: screen));
    await tester.pump();

    expect(find.byType(EmptyState), findsWidgets,
        reason: 'this screen was expected to draw its empty state with '
            'nothing synced — if it no longer does, the check below is '
            'passing on an absence');
    expect(
      find.ancestor(
        of: find.byType(EmptyState).first,
        matching: find.byType(RefreshIndicator),
      ),
      findsOneWidget,
      reason: 'a RefreshIndicator recognises a pull only on a scrollable '
          'descendant, so an EmptyState outside one makes the "নিচে টেনে" '
          'instruction printed on that very screen do nothing',
    );
  }

  testWidgets('গ্রাহক', (t) => expectPullable(t, const CustomerListScreen()));
  testWidgets('পণ্য', (t) => expectPullable(t, const ProductListScreen()));
  testWidgets('মজুদ', (t) => expectPullable(t, const StockListScreen()));
  testWidgets('বকেয়া', (t) => expectPullable(t, const DueListScreen()));
  testWidgets('নোটিফিকেশন', (t) => expectPullable(t, NotificationsScreen(
      fetch: () async => const NotificationPage(items: [], unread: 0))));

  testWidgets('কোপাইলট — আনতে না পারলে', (t) => expectPullable(t, const CopilotScreen(api: _FailingCopilot())));

  testWidgets('অর্ডার — the screen the hand-written list missed',
      (t) => expectPullable(t, const OrderListScreen()));

  testWidgets('অনুমোদন', (t) async {
    await expectPullable(
      t,
      ApprovalInboxScreen(
          loadPending: () async => const ApprovalPage(rows: [])),
    );
  });

  testWidgets('হাজিরা', (t) async {
    await expectPullable(
      t,
      AttendanceScreen(now: () => DateTime(2026, 9, 16), onMark: (_) async {}),
    );
  });

  testWidgets('রিপোর্টের তালিকা', (t) async {
    await expectPullable(t, ReportsScreen(loadList: () async => const []));
  });

  testWidgets('মেনুর রিপোর্ট-সারি, চাবি তালিকায় নেই', (t) async {
    await expectPullable(t, ReportByKeyScreen(reportKey: 'accounts.trial_balance', loadList: () async => const []));
  });

  testWidgets('একটা রিপোর্ট, যেখানে কোনো সারি নেই', (tester) async {
    // The second screen inside reports_screen.dart. With no rows there is no
    // pager either, so before this it was a dead end: back out and tap the
    // report again was the only way on.
    await tester.pumpWidget(MaterialApp(
      home: ReportsScreen(
        loadList: () async => const [
          ReportSummary({'key': 'sales.daily', 'title': 'দৈনিক বিক্রয়'})
        ],
        open: (_, __, ___) async => const ReportPage({
          'columns': [
            {'key': 'amount', 'label': 'টাকা', 'type': 'money'},
          ],
          'rows': [],
          'page': 1,
          'lastPage': 1,
          'totalRows': 0,
        }),
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('দৈনিক বিক্রয়'));
    await tester.pumpAndSettle();

    expect(find.text('এই সময়ে কোনো সারি নেই'), findsOneWidget);
    expect(
      find.ancestor(
        of: find.text('এই সময়ে কোনো সারি নেই'),
        matching: find.byType(RefreshIndicator),
      ),
      findsOneWidget,
    );
  });

  test('সিঙ্কের অবস্থা — checked in the source, and here is why', () {
    // ⚠️ A weaker check than the ones above, on purpose. This screen loads
    // from the network in initState, and AppConfig now points at the live
    // server — pumping it in a widget test would put a test run's traffic on
    // the company's own ERP. So the structure is read instead of driven.
    final source =
        File('lib/features/sync/sync_status_screen.dart').readAsStringSync();

    final refresh = source.indexOf('RefreshIndicator(');
    final empty = source.indexOf('EmptyState(');

    expect(refresh, isNonNegative,
        reason: 'the sync screen no longer has a RefreshIndicator at all');
    expect(empty, isNonNegative);
    expect(refresh, lessThan(empty),
        reason: 'its EmptyState is written before the RefreshIndicator, the '
            'exact shape the orders screen had while its pull did nothing');
  });
}

/// ⓘ সার্ভার না পেলে কোপাইলট খালি অবস্থা আঁকে — সেটাই নিচে টানা যায় কিনা দেখা
class _FailingCopilot implements CopilotApi {
  const _FailingCopilot();
  @override
  Future<Map<String, dynamic>> today() async => throw Exception('offline');
  @override
  Future<Map<String, dynamic>> ask(String question) async => throw Exception('offline');
  @override
  Future<void> feedback(int answerId, int value) async {}
  @override
  Future<void> dismiss(String finding, String reason) async {}
}
