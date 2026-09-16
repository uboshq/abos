import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/report_record.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/attendance/attendance_screen.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';
import 'package:abos_mobile/features/products/product_list_screen.dart';
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
    'customer_list_screen.dart',
    'product_list_screen.dart',
    'stock_list_screen.dart',
    'order_list_screen.dart',
    'due_list_screen.dart',
    'approval_inbox_screen.dart',
    'attendance_screen.dart',
    'reports_screen.dart',
    'sync_status_screen.dart',
  };

  /// Screens whose empty state is deliberately **not** pull-to-refresh, each
  /// with its reason. Being here is a decision somebody made, not an oversight
  /// — which is the whole difference this file is trying to keep.
  const notPullable = {
    // The menu is built from GET /me at sign-in; there is nothing on this
    // screen for a pull to fetch, and its message sends the person to the
    // office rather than to a gesture.
    'home_shell.dart',
    // Two empty states, neither refreshable here: the catalogue one sends the
    // person to the products screen to pull *there*, and the other is a
    // search box reporting no match, where a pull would fetch nothing the
    // typing did not already decide.
    'new_order_screen.dart',
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

  testWidgets('অর্ডার — the screen the hand-written list missed',
      (t) => expectPullable(t, const OrderListScreen()));

  testWidgets('অনুমোদন', (t) async {
    await expectPullable(
      t,
      ApprovalInboxScreen(loadPending: () async => const ApprovalPage(rows: [])),
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

  testWidgets('একটা রিপোর্ট, যেখানে কোনো সারি নেই', (tester) async {
    // The second screen inside reports_screen.dart. With no rows there is no
    // pager either, so before this it was a dead end: back out and tap the
    // report again was the only way on.
    await tester.pumpWidget(MaterialApp(
      home: ReportsScreen(
        loadList: () async =>
            const [ReportSummary({'key': 'sales.daily', 'title': 'দৈনিক বিক্রয়'})],
        open: (_, __) async => const ReportPage({
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
