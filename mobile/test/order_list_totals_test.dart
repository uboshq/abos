import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ ফোনের আদেশের তালিকার মোট — ওয়েবের হুবহু নিয়ম (fe-র মোটের নিরীক্ষা, ২৪ অক্টোবর ২০২৬; ০.৪.৪৮)।
///
/// <p>দাবি:
/// - প্রত্যাখ্যাত আর বাতিল আদেশ সারিতে থাকে, মোটে নয় (সার্ভারের `SalesOrderStatus::UNCOUNTED`): পাকা ৳1,000, প্রত্যাখ্যাত ৳500,
///   বাতিল ৳300 → মোট ৳1,000। ⛔ আগে প্রত্যাখ্যাতটা মোটে ঢুকত (৳1,500)।
/// - সর্বমোট ছাঁকনি মানে: একটা অবস্থা বাছলে যে একটা পাকা আদেশ মেলে, সর্বমোট ঠিক সেটাই। ⛔ আগে ছাঁকনির আগের পুরো তালিকা।
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

  Future<void> seed(WidgetTester tester, List<(String, String, String)> orders) async {
    final now = DateTime(2026, 10, 1);
    await tester.runAsync(() async {
      await ReferenceCache.instance.put(entityType: 'Customer', entityId: 'c-1', updatedAt: now, payload: const {
        'id': 'c-1',
        'nameBn': 'রহিম স্টোর',
        'pointName': 'ফুলপুর',
        'branchName': 'ময়মনসিংহ',
      });
      for (final (i, (id, status, total)) in orders.indexed) {
        await ReferenceCache.instance.put(entityType: 'SalesOrder', entityId: id, updatedAt: now, payload: {
          'id': id,
          'documentNo': 'SO-000${i + 1}',
          'customerId': 'c-1',
          'trxDate': '2026-10-0${i + 1}',
          'status': status,
          'total': total,
        });
      }
    });
  }

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(400, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: const OrderListScreen()));
    await tester.pumpAndSettle();
  }

  String line(WidgetTester tester, String key) => tester
      .widgetList<Text>(find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(Text)))
      .map((t) => t.data)
      .join(' | ');

  testWidgets('a rejected and a cancelled order stay in the rows and out of the total', (tester) async {
    await seed(tester, [('o1', 'confirmed', '1000.0000'), ('o2', 'rejected', '500.0000'), ('o3', 'cancelled', '300.0000')]);
    await pump(tester);

    for (final id in ['o1', 'o2', 'o3']) {
      expect(find.byKey(ValueKey('order-row-$id')), findsOneWidget, reason: 'সারিতে থাকে');
    }
    expect(line(tester, 'totals-all'), contains('৳1,000.00'),
        reason: '⛔ প্রত্যাখ্যাত বা বাতিল আদেশ মোটে ঢুকেছে — ওয়েব কেবল ৳1,000 বলে (SalesOrderStatus::UNCOUNTED)');
    expect(line(tester, 'totals-page'), contains('৳1,000.00'),
        reason: '⛔ প্রত্যাখ্যাত বা বাতিল আদেশ "এই পাতা"-র মোটে ঢুকেছে');
  });

  testWidgets('the grand total follows the filter: one confirmed order shown, the grand total is that order', (tester) async {
    await seed(tester, [
      ('o1', 'confirmed', '1000.0000'),
      ('o2', 'approved', '250.0000'),
      ('o3', 'rejected', '500.0000'),
    ]);
    await pump(tester);
    expect(line(tester, 'totals-all'), contains('৳1,250.00'), reason: 'ছাঁকনি ছাড়া: পাকা আর অনুমোদিত, প্রত্যাখ্যাত বাদ');

    await tester.tap(find.byKey(const Key('list-controls-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('filter-status-confirmed')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('filter-apply')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('order-row-o1')), findsOneWidget);
    expect(find.byKey(const ValueKey('order-row-o2')), findsNothing);
    final all = line(tester, 'totals-all');
    expect(all, contains('৳1,000.00'), reason: '⛔ সর্বমোট ছাঁকনি মানেনি — দেখানো একটা আদেশই ৳1,000');
    expect(all, contains('1টি'), reason: '⛔ সর্বমোটের গোনা ছাঁকনি মানেনি');
  });
}
