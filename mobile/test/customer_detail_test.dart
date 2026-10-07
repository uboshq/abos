import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/features/customers/customer_detail_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// One shop, from what the phone already knows.
///
/// <p>Nothing new from the server: `Customer`, `CustomerDue` and `SalesOrder`
/// have all been syncing to the device, and the only way to see one shop's
/// history was to scroll the orders list hunting for its name.
void main() {
  const id = '01a0c3f0-0000-7000-8000-000000000001';
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async => harness.tearDown());

  setUp(() async => ReferenceCache.instance.clearAll());

  Future<void> seedShop({bool active = true}) => ReferenceCache.instance.put(
        entityType: 'Customer',
        entityId: id,
        updatedAt: DateTime(2026, 9, 16),
        payload: {
          'id': id,
          'code': 'CUS-0001',
          'nameBn': 'রহিম স্টোর',
          'nameEn': 'Rahim Store',
          'ownerName': 'আব্দুর রহিম',
          'phone': '01711000001',
          'addressBn': 'মিরপুর ১০, ঢাকা',
          'isActive': active,
        },
      );

  Widget screen() =>
      const MaterialApp(home: CustomerDetailScreen(customerId: id));

  testWidgets('shows who they are and what they owe', (tester) async {
    await tester.runAsync(() async {
      await seedShop();
      await ReferenceCache.instance.put(
        entityType: 'CustomerDue',
        entityId: id,
        updatedAt: DateTime(2026, 9, 16, 9, 30),
        payload: const {
          'customerId': id,
          'outstanding': '8125.5000',
          'creditLimit': '50000.0000',
          'creditDays': 15,
        },
      );
    });

    await tester.pumpWidget(screen());
    await tester.pump();

    expect(find.text('রহিম স্টোর'), findsWidgets);
    expect(find.text('CUS-0001'), findsOneWidget);
    expect(find.text('আব্দুর রহিম'), findsOneWidget);
    expect(find.text('বকেয়া ৳8,125.5'), findsOneWidget);
    expect(find.textContaining('সীমা ৳50,000'), findsOneWidget);
    // How old the figure is, because this is the number somebody is about to
    // promise goods against and a stale one looks exactly like a fresh one.
    expect(find.textContaining('সিঙ্ক 16/09/2026'), findsOneWidget);
  });

  testWidgets('lists this shop\'s orders, newest first', (tester) async {
    await tester.runAsync(() async {
      await seedShop();
      await ReferenceCache.instance.put(
        entityType: 'SalesOrder',
        entityId: 'o1',
        updatedAt: DateTime(2026, 9, 10),
        payload: {
          'id': 'o1',
          'documentNo': 'SO-2609-0001',
          'customerId': id,
          'trxDate': '2026-09-10',
          'status': 'confirmed',
          'total': '1200.0000',
        },
      );
      await ReferenceCache.instance.put(
        entityType: 'SalesOrder',
        entityId: 'o2',
        updatedAt: DateTime(2026, 9, 15),
        payload: {
          'id': 'o2',
          'documentNo': 'SO-2609-0009',
          'customerId': id,
          'trxDate': '2026-09-15',
          'status': 'draft',
          'total': '3400.0000',
        },
      );
      // Another shop's order must not appear here.
      await ReferenceCache.instance.put(
        entityType: 'SalesOrder',
        entityId: 'o3',
        updatedAt: DateTime(2026, 9, 16),
        payload: const {
          'id': 'o3',
          'documentNo': 'SO-2609-0011',
          'customerId': 'somebody-else',
          'trxDate': '2026-09-16',
          'status': 'confirmed',
          'total': '999.0000',
        },
      );
    });

    await tester.pumpWidget(screen());
    await tester.pump();

    expect(find.text('এই দোকানের অর্ডার (2)'), findsOneWidget);
    expect(find.text('SO-2609-0011'), findsNothing);

    final titles = tester
        .widgetList<ListTile>(find.byType(ListTile))
        .map((t) => (t.title! as Text).data)
        .toList();
    expect(titles, ['SO-2609-0009', 'SO-2609-0001']);
  });

  testWidgets('no synced orders is said carefully', (tester) async {
    await tester.runAsync(seedShop);

    await tester.pumpWidget(screen());
    await tester.pump();

    // Not "this shop has never ordered" — only orders that synced back are
    // here, and a phone set up this morning has none of them.
    expect(find.textContaining('সিঙ্ক হয়নি'), findsOneWidget);
  });

  testWidgets('an inactive shop says so', (tester) async {
    await tester.runAsync(() => seedShop(active: false));

    await tester.pumpWidget(screen());
    await tester.pump();

    expect(find.textContaining('নিষ্ক্রিয়'), findsOneWidget);
  });

  testWidgets('a shop that left the catalogue does not draw blanks',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pump();

    // A re-sync or a deactivation can remove a row between opening a list and
    // tapping it. A screen of empty fields looks like a bug; this says what
    // happened.
    expect(find.textContaining('আর এই ফোনে নেই'), findsOneWidget);
  });

  testWidgets('a shop with no due shows no due card at all', (tester) async {
    await tester.runAsync(seedShop);

    await tester.pumpWidget(screen());
    await tester.pump();

    // CustomerDue has its own watermark and can lag Customer by a sync.
    // "বকেয়া নেই" when the figure has simply not arrived is the one sentence
    // that costs money.
    expect(find.textContaining('বকেয়া'), findsNothing);
  });
}
