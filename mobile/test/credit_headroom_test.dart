import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/features/orders/new_order_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// The subtraction nobody can do standing in a shop.
///
/// <p>The order screen already showed *বকেয়া ৳45,000 · সীমা ৳50,000*. A rep
/// with ৳12,000 in the cart then has to hold three numbers and subtract
/// correctly to know they are about to write an order the office will refuse
/// — and the refusal reaches them hours later, after the goods were promised
/// and often after they were handed over.
///
/// <p><b>It states, it does not decide.</b> `CustomerDueSync`'s own comment is
/// explicit that a zero credit limit means cash or advance rather than "no
/// sale", and that whether a limit blocks anything is a company switch the
/// phone is deliberately not sent. So this is a sentence, never a disabled
/// button: a phone refusing an order from its own cached copy of a limit
/// would be wrong in both directions.
void main() {
  const customerId = '01a0c3f0-0000-7000-8000-000000000001';
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async => harness.tearDown());

  setUp(() async => ReferenceCache.instance.clearAll());

  Future<void> seed({
    required String outstanding,
    required String creditLimit,
    DateTime? syncedAt,
  }) async {
    await ReferenceCache.instance.put(
      entityType: 'Customer',
      entityId: customerId,
      updatedAt: DateTime(2026, 9, 16),
      payload: const {
        'id': customerId,
        'code': 'CUS-0001',
        'nameBn': 'রহিম স্টোর',
        'isActive': true,
      },
    );
    await ReferenceCache.instance.put(
      entityType: 'CustomerDue',
      entityId: customerId,
      updatedAt: syncedAt ?? DateTime(2026, 9, 16, 9, 30),
      payload: {
        'customerId': customerId,
        'outstanding': outstanding,
        'creditLimit': creditLimit,
        'creditDays': 15,
      },
    );
  }

  Future<void> pump(WidgetTester tester, double orderTotal) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: DueNotice(customerId: customerId, orderTotal: orderTotal),
      ),
    ));
    await tester.pump();
  }

  testWidgets('an order inside the limit says how much room is left',
      (tester) async {
    await tester.runAsync(
        () => seed(outstanding: '45000.0000', creditLimit: '50000.0000'));

    await pump(tester, 3000);

    expect(find.textContaining('বাকি ৳5,000'), findsOneWidget);
    expect(find.textContaining('ছাড়িয়ে যাবে'), findsNothing);
  });

  testWidgets('an order that crosses the limit says by how much',
      (tester) async {
    await tester.runAsync(
        () => seed(outstanding: '45000.0000', creditLimit: '50000.0000'));

    // ৳5,000 of room, ৳12,000 in the cart.
    await pump(tester, 12000);

    expect(find.text('এই অর্ডারে সীমা ৳7,000 ছাড়িয়ে যাবে।'), findsOneWidget);
  });

  testWidgets('a shop already over the limit is not blamed on the cart',
      (tester) async {
    await tester.runAsync(
        () => seed(outstanding: '62000.0000', creditLimit: '50000.0000'));

    await pump(tester, 3000);

    // ⛔ "সীমা ৳15,000 ছাড়িয়ে যাবে" would read as though this ৳3,000 order
    // caused it, and the rep would try a smaller order to get under. Nothing
    // in the cart can — they were ৳12,000 over before it was opened.
    expect(find.textContaining('সীমা আগেই পেরিয়ে আছে'), findsOneWidget);
    expect(find.textContaining('৳3,000 যোগ হবে'), findsOneWidget);
  });

  testWidgets('an empty cart concludes nothing', (tester) async {
    await tester.runAsync(
        () => seed(outstanding: '62000.0000', creditLimit: '50000.0000'));

    await pump(tester, 0);

    // The shop is over its limit, but no order has been started — a warning
    // here is a scolding, not information, and the figures above already say
    // what the position is.
    expect(find.textContaining('পেরিয়ে'), findsNothing);
    expect(find.textContaining('ছাড়িয়ে'), findsNothing);
  });

  testWidgets('no credit limit set means no conclusion drawn', (tester) async {
    // A zero limit is cash or advance, not "no sale". Treating it as a limit
    // of ৳0 would flag every order this shop ever places as over the limit.
    await tester.runAsync(
        () => seed(outstanding: '45000.0000', creditLimit: '0.0000'));

    await pump(tester, 12000);

    expect(find.textContaining('ছাড়িয়ে'), findsNothing);
    expect(find.textContaining('সীমা ৳'), findsNothing);
  });

  testWidgets('the warning carries the age of the figure it is built on',
      (tester) async {
    await tester.runAsync(() => seed(
          outstanding: '45000.0000',
          creditLimit: '50000.0000',
          syncedAt: DateTime(2026, 9, 12, 16, 5),
        ));

    await pump(tester, 12000);

    // ⚠️ CustomerDue has its own watermark and can lag Customer by a sync.
    // Displaying a stale figure is tolerable; concluding "you are over the
    // limit" from a four-day-old one without saying so is not.
    expect(find.textContaining('সিঙ্ক 12/09/2026'), findsOneWidget);
  });

  testWidgets('a due that has not synced at all says nothing either way',
      (tester) async {
    await tester.runAsync(() async {
      await ReferenceCache.instance.put(
        entityType: 'Customer',
        entityId: customerId,
        updatedAt: DateTime(2026, 9, 16),
        payload: const {'id': customerId, 'nameBn': 'রহিম স্টোর'},
      );
    });

    await pump(tester, 12000);

    // Same rule as everywhere else in this app: "বকেয়া নেই" when the figure
    // has simply not arrived is the one sentence that costs money.
    expect(find.textContaining('বকেয়া'), findsNothing);
  });
}
