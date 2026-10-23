import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ গ্রাহকের তালিকার মোট নিট — ওয়েবের গ্রাহক-তালিকার সর্বমোটের হুবহু (CustomerController: `outstanding_in_view` চিহ্নসহ যোগ;
/// fe-র মোটের নিরীক্ষা, ২৪ অক্টোবর ২০২৬; ০.৪.৪৮)।
///
/// <p>দাবি, নিরীক্ষার উদাহরণে: A দেবে ৳10,000, B-র অগ্রিম ৳3,000 → নিট ৳7,000, বকেয়া ৳10,000, অগ্রিম ৳3,000।
/// ⛔ আগে কেবল বকেয়া যোগ হত (৳10,000) — অগ্রিম বাদ পড়ত, ওয়েবের চেয়ে বড় অঙ্ক।
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

  String textsIn(WidgetTester tester, Finder of) =>
      tester.widgetList<Text>(find.descendant(of: of, matching: find.byType(Text))).map((t) => t.data).join(' | ');

  testWidgets('A owes ৳10,000 and B has a ৳3,000 advance: net ৳7,000, dues ৳10,000, advances ৳3,000', (tester) async {
    final now = DateTime(2026, 10, 1);
    await tester.runAsync(() async {
      for (final (id, name, balance) in [('c-a', 'এ স্টোর', '10000.0000'), ('c-b', 'বি স্টোর', '-3000.0000')]) {
        await ReferenceCache.instance.put(entityType: 'Customer', entityId: id, updatedAt: now, payload: {
          'id': id,
          'nameBn': name,
          'pointName': 'ফুলপুর',
          'branchName': 'ময়মনসিংহ',
        });
        await ReferenceCache.instance.put(entityType: 'CustomerDue', entityId: id, updatedAt: now, payload: {
          'customerId': id,
          'outstanding': balance,
          'outstandingInView': balance,
          'creditLimit': '0.0000',
          'creditDays': 0,
          'isActive': true,
        });
      }
    });
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: const CustomerListScreen()));
    await tester.pumpAndSettle();

    final net = textsIn(tester, find.byKey(const ValueKey('books-total')));
    expect(net, contains('নিট বকেয়া'));
    expect(net, contains('৳7,000.00'), reason: '⛔ নিট ভুল — ওয়েব বকেয়া থেকে অগ্রিম কেটে ৳7,000 বলে');
    expect(textsIn(tester, find.byKey(const ValueKey('customers-total-dues'))), contains('৳10,000.00'),
        reason: '⛔ বকেয়ার লাইন ভুল');
    expect(textsIn(tester, find.byKey(const ValueKey('customers-total-advances'))), contains('৳3,000.00'),
        reason: '⛔ অগ্রিমের লাইন ভুল বা নেই');
  });

  test('the totals add in paisa: no float drift, the sign decides dues or advance', () {
    final t = CustomerTotals.of([0.1, 0.2, -0.3, 0]);
    expect([t.dues, t.advances, t.net], [0.3, 0.3, 0.0]);
  });
}
