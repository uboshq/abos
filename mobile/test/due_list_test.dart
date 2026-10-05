import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// The due list — who owes what.
///
/// <p>Built from `CustomerDue`, which has been arriving on the phone since
/// the sync engine was written and was read by exactly two lines of UI. The
/// question an owner asks on a Thursday — *where is my money* — had no screen
/// at all.
void main() {
  late HiveTestHarness harness;

  Future<void> seed({
    required String id,
    required String nameBn,
    String? phone,
    String? pointName,
    required String outstanding,
    String creditLimit = '0.0000',
    int creditDays = 0,
  }) async {
    await ReferenceCache.instance.put(
      entityType: 'Customer',
      entityId: id,
      updatedAt: DateTime(2026, 9, 16),
      payload: {
        'id': id,
        'nameBn': nameBn,
        if (phone != null) 'phone': phone,
        if (pointName != null) 'pointName': pointName,
      },
    );
    await ReferenceCache.instance.put(
      entityType: 'CustomerDue',
      entityId: id,
      updatedAt: DateTime(2026, 9, 16),
      payload: {
        'customerId': id,
        'outstanding': outstanding,
        'creditLimit': creditLimit,
        'creditDays': creditDays,
      },
    );
  }

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async => harness.tearDown());

  setUp(() async => ReferenceCache.instance.clearAll());

  testWidgets('the biggest debt is at the top, and the total is the total',
      (tester) async {
    await tester.runAsync(() async {
      await seed(id: 'c1', nameBn: 'ছোট দোকান', outstanding: '1200.0000');
      await seed(
          id: 'c2',
          nameBn: 'রহিম স্টোর',
          phone: '01711000001',
          outstanding: '8125.5000',
          creditLimit: '50000.0000',
          creditDays: 15);
      await seed(id: 'c3', nameBn: 'মাঝারি দোকান', outstanding: '3000.0000');
    });

    await tester.pumpWidget(const MaterialApp(home: DueListScreen()));
    await tester.pump();

    // 8125.5 + 3000 + 1200
    expect(find.text('৳12,325.5'), findsOneWidget);
    expect(find.text('3 টি দোকান'), findsOneWidget);

    // Sorted by what is owed, not by name: a list of eighty shops in
    // alphabetical order is a list nobody reads to the end.
    final tiles = tester.widgetList<ListTile>(find.byType(ListTile)).toList();
    final names = tiles.map((t) => (t.title! as Text).data).toList();
    expect(names, ['রহিম স্টোর', 'মাঝারি দোকান', 'ছোট দোকান']);
  });

  // ⭐ The owner, 6 Oct 2026: two lines — the name, then "পয়েন্ট: …" with
  // "বকেয়া: …" beside it. The mobile is on the shop's own page, a tap away.
  testWidgets('two lines: the name, then the point beside the due; no mobile on the row',
      (tester) async {
    await tester.runAsync(() async {
      await seed(
          id: 'c1',
          nameBn: 'রহিম স্টোর',
          phone: '01711000001',
          pointName: 'কারওয়ান বাজার',
          outstanding: '5000.0000');
      await seed(
          id: 'c2',
          nameBn: 'করিম স্টোর',
          phone: '01711000002',
          outstanding: '3000.0000');
    });

    await tester.pumpWidget(const MaterialApp(home: DueListScreen()));
    await tester.pump();

    expect(find.text('পয়েন্ট: কারওয়ান বাজার'), findsOneWidget);
    expect(find.text('বকেয়া: ৳5,000'), findsOneWidget);
    // ⓘ A shop on no point keeps its due line, with nothing made up beside it
    expect(find.text('বকেয়া: ৳3,000'), findsOneWidget);
    expect(find.textContaining('পয়েন্ট: '), findsOneWidget);
    expect(find.textContaining('01711000001'), findsNothing,
        reason: 'the mobile is on the shop page, not the row');
    final tiles = tester.widgetList<ListTile>(find.byType(ListTile));
    expect(tiles.every((t) => t.onTap != null), isTrue,
        reason: 'a tap opens the shop, where the mobile is');
  });

  testWidgets('a shop in advance is not listed as a negative debt',
      (tester) async {
    await tester.runAsync(() async {
      await seed(id: 'c1', nameBn: 'অগ্রিমওয়ালা', outstanding: '-2000.0000');
      await seed(id: 'c2', nameBn: 'সমান', outstanding: '0.0000');
    });

    await tester.pumpWidget(const MaterialApp(home: DueListScreen()));
    await tester.pump();

    // This page answers one question — who owes me — and money owed back is a
    // different question. Mixing them makes the total meaningless.
    expect(find.text('কারো কাছে বকেয়া নেই'), findsOneWidget);
    expect(find.textContaining('অগ্রিমওয়ালা'), findsNothing);
  });

  testWidgets('the limit and the days are on the shop page, not the row, and never judged',
      (tester) async {
    await tester.runAsync(() => seed(
        id: 'c1',
        nameBn: 'রহিম স্টোর',
        outstanding: '60000.0000',
        creditLimit: '50000.0000',
        creditDays: 15));

    await tester.pumpWidget(const MaterialApp(home: DueListScreen()));
    await tester.pump();

    // ⓘ Two lines only (owner, 6 Oct 2026) — the shop's page carries them.
    expect(find.textContaining('সীমা ৳50,000'), findsNothing);
    expect(find.textContaining('15 দিনের শর্ত'), findsNothing);
    expect(find.text('বকেয়া: ৳60,000'), findsOneWidget);
    // ⛔ Over the limit, and the screen still draws no verdict. Whether a
    // limit blocks anything is a company switch the phone is deliberately not
    // sent — CustomerDueSync says so — and a phone deciding on its own cached
    // copy would be wrong in both directions.
    expect(find.textContaining('সীমা ছাড়িয়েছে'), findsNothing);
    expect(find.textContaining('বন্ধ'), findsNothing);
  });

  testWidgets('a customer whose due has not arrived is simply absent',
      (tester) async {
    await tester.runAsync(() => ReferenceCache.instance.put(
          entityType: 'Customer',
          entityId: 'lonely',
          updatedAt: DateTime(2026, 9, 16),
          payload: const {'id': 'lonely', 'nameBn': 'একলা দোকান'},
        ));

    await tester.pumpWidget(const MaterialApp(home: DueListScreen()));
    await tester.pump();

    // Not the same as owing nothing: the two entity types have separate
    // watermarks, so one can lag the other by a sync. Showing it as ৳0 would
    // be the one sentence that costs money.
    expect(find.text('কারো কাছে বকেয়া নেই'), findsOneWidget);
    expect(find.textContaining('একলা দোকান'), findsNothing);
  });

  testWidgets('search narrows the list without changing the total',
      (tester) async {
    await tester.runAsync(() async {
      await seed(
          id: 'c1', nameBn: 'রহিম স্টোর', outstanding: '8000.0000');
      await seed(id: 'c2', nameBn: 'করিম ট্রেডার্স', outstanding: '2000.0000');
    });

    await tester.pumpWidget(const MaterialApp(home: DueListScreen()));
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'রহিম');
    await tester.pump();

    expect(find.textContaining('রহিম স্টোর'), findsOneWidget);
    expect(find.textContaining('করিম'), findsNothing);
    // The headline stays the whole round's debt — a filtered total would read
    // as "this is what I am owed" while showing a fraction of it.
    expect(find.text('৳10,000'), findsOneWidget);
  });
}
