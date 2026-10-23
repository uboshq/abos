import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ মোটের বাইরের প্রত্যাখ্যাত সারি নিজের আলাদা লাইনে (fe-র নিয়ম, ২৪ অক্টোবর ২০২৬; ০.৪.৪৮)।
///
/// <p>দাবি: আদেশের তালিকা আর ডিওর ডেস্কে দেখানো প্রত্যাখ্যাত আদেশ মোটে নেই, আর "প্রত্যাখ্যাত, মোটে নেই · 1টি  ৳…" লাইনে আছে;
/// কোনো প্রত্যাখ্যাত না থাকলে লাইনটাই নেই।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  setUp(() async {
    await ReferenceCache.instance.clearAll();
    ReferenceSync.forgetLastFailure();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  Future<void> pump(WidgetTester tester, Widget home) async {
    tester.view.physicalSize = const Size(400, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: home));
    await tester.pumpAndSettle();
  }

  String textsIn(WidgetTester tester, Finder of) =>
      tester.widgetList<Text>(find.descendant(of: of, matching: find.byType(Text))).map((t) => t.data).join(' | ');

  testWidgets('orders: the rejected ৳500 is out of the total and on its own line', (tester) async {
    final now = DateTime(2026, 10, 1);
    await tester.runAsync(() async {
      for (final (id, status, total) in [('o1', 'confirmed', '1000.0000'), ('o2', 'rejected', '500.0000'), ('o3', 'cancelled', '300.0000')]) {
        await ReferenceCache.instance.put(entityType: 'SalesOrder', entityId: id, updatedAt: now, payload: {
          'id': id, 'documentNo': 'SO-$id', 'customerId': 'c-1', 'trxDate': '2026-10-01', 'status': status, 'total': total,
        });
      }
    });
    await pump(tester, const OrderListScreen());
    final line = find.byKey(const ValueKey('totals-rejected'));
    expect(line, findsOneWidget, reason: '⛔ প্রত্যাখ্যাত আদেশ দেখানো, অথচ আলাদা লাইনে নেই');
    expect(textsIn(tester, line), 'প্রত্যাখ্যাত, মোটে নেই · 1টি | ৳500.00', reason: '⛔ প্রত্যাখ্যাতের লাইনের গোনা বা টাকা ভুল');
    expect(textsIn(tester, find.byKey(const ValueKey('totals-all'))), contains('৳1,000.00'));
  });

  testWidgets('orders: no rejected order, no rejected line', (tester) async {
    await tester.runAsync(() => ReferenceCache.instance.put(entityType: 'SalesOrder', entityId: 'o1', updatedAt: DateTime(2026, 10, 1),
        payload: const {'id': 'o1', 'documentNo': 'SO-1', 'customerId': 'c-1', 'status': 'confirmed', 'total': '10'}));
    await pump(tester, const OrderListScreen());
    expect(find.byKey(const ValueKey('totals-rejected')), findsNothing);
  });

  testWidgets('delivery orders: the rejected ৳700 shown is on its own line', (tester) async {
    await pump(tester, DeliveryOrderListScreen(api: _Orders(), canWrite: false));
    final line = find.byKey(const ValueKey('totals-rejected'));
    expect(line, findsOneWidget, reason: '⛔ প্রত্যাখ্যাত ডিও দেখানো, অথচ আলাদা লাইনে নেই');
    expect(textsIn(tester, line), 'প্রত্যাখ্যাত, মোটে নেই · 1টি | ৳700.00', reason: '⛔ প্রত্যাখ্যাতের লাইনের গোনা বা টাকা ভুল');
    expect(textsIn(tester, find.byKey(const ValueKey('totals-page'))), contains('৳1,500.00'));
  });
}

DeliveryOrder _do(String id, String status, double total) => DeliveryOrder(
    id: id, no: 'DO-$id', date: '2026-10-20', customer: 'রহিম স্টোর', status: status, statusLabel: status, total: total,
    editable: false, awaitingMe: false);

class _Orders implements DeliveryOrderApi {
  @override
  bool get forOrders => false;

  @override
  Future<(List<DeliveryOrder>, PaperTotal?, int?)> list({bool awaitingMe = false, int page = 1}) async =>
      ([_do('a', 'supervisor_pending', 1500), _do('b', 'rejected', 700), _do('c', 'cancelled', 90)],
          const PaperTotal(sum: 1500, count: 3), null);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
