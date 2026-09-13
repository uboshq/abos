import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/orders/new_order_screen.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';
import 'package:abos_mobile/features/products/product_list_screen.dart';
import 'package:abos_mobile/features/stock/stock_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// The screens, run against the server's real payloads, asserting that the
/// figures a rep needs actually reach the glass.
///
/// <p><b>Why this file exists separately from `payload_contract_test.dart`.</b>
/// That file proves the readers parse the payloads and that the handlers
/// still send those keys. Neither proves the thing that actually failed:
/// every screen *read* a key that did not exist, got null, and drew nothing.
/// A reader can be perfect and a screen still show "নাম নেই" — the bug was
/// never in parsing, it was in the wiring between them, and only running the
/// widget catches that.
///
/// <p><b>This is the test that would have caught the original bug.</b> Put
/// one real `Customer` payload in the cache, pump the list, and look for the
/// shop's name on screen. It was never done, and everything else was green
/// for a month: `flutter analyze` clean, 18/18 passing, and not one figure
/// visible on a phone.
///
/// <p>It does not need a server, and deliberately so — the payloads here are
/// the same literals the contract test pins to the PHP handlers. Waiting for
/// a live login to learn whether a name draws would be waiting for the wrong
/// thing.
void main() {
  late HiveTestHarness harness;

  // Copied from the handlers, exactly as in payload_contract_test.dart.
  const customerId = '01a0c3f0-0000-7000-8000-000000000001';
  const productId = '01a0c3f0-0000-7000-8000-000000000009';

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    // OrderListScreen and NewOrderScreen both read the queue directly.
    await SyncEngine.instance.init();

    final now = DateTime(2026, 9, 12);

    await ReferenceCache.instance.put(
      entityType: 'Customer',
      entityId: customerId,
      updatedAt: now,
      payload: const {
        'id': customerId,
        'code': 'C-0001',
        'nameEn': 'Rahim Store',
        'nameBn': 'রহিম স্টোর',
        'ownerName': 'Abdur Rahim',
        'phone': '01711000000',
        'addressEn': 'Mirpur 10',
        'addressBn': 'মিরপুর ১০',
        'customerType': 'retail',
        'creditLimit': '50000.0000',
        'creditDays': 15,
        'isActive': true,
      },
    );

    await ReferenceCache.instance.put(
      entityType: 'CustomerDue',
      entityId: customerId,
      updatedAt: now,
      payload: const {
        'customerId': customerId,
        'outstanding': '8125.5000',
        'creditLimit': '50000.0000',
        'creditDays': 15,
        'isActive': true,
      },
    );

    await ReferenceCache.instance.put(
      entityType: 'Product',
      entityId: productId,
      updatedAt: now,
      payload: const {
        'id': productId,
        'code': 'P-0001',
        'nameEn': 'Lifebuoy Soap 100g',
        'nameBn': 'লাইফবয় সাবান ১০০গ্রাম',
        'barcode': '8901030',
        'unitCode': 'PCS',
        'unitNameBn': 'পিস',
        'salePrice': '42.5000',
        'isActive': true,
      },
    );

    await ReferenceCache.instance.put(
      entityType: 'StockOnHand',
      entityId: productId,
      updatedAt: now,
      payload: const {
        'productId': productId,
        'floor': '100.0000',
        'reserved': '55.0000',
        'hold': '5.0000',
        'available': '40.0000',
        'freeAvailable': '0.0000',
      },
    );

    await ReferenceCache.instance.put(
      entityType: 'SalesOrder',
      entityId: '01a0c3f0-0000-7000-8000-00000000000f',
      updatedAt: now,
      payload: const {
        'id': '01a0c3f0-0000-7000-8000-00000000000f',
        'documentNo': 'SO-2609-0042',
        'customerId': customerId,
        'trxDate': '2026-09-05',
        'deliverOn': null,
        'status': 'confirmed',
        'total': '1275.0000',
        'narration': 'সকালের রাউন্ড',
      },
    );
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  testWidgets('the customer list draws the shop, its phone and its due',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: CustomerListScreen()));
    await tester.pump();

    // The name. This is the assertion that was missing: before the fix the
    // screen read `name`, the server sends `nameBn`, and every row on a real
    // phone read "নাম নেই".
    expect(find.text('রহিম স্টোর'), findsOneWidget);
    expect(find.text('নাম নেই'), findsNothing);

    // Phone and address share one subtitle line.
    expect(find.textContaining('01711000000'), findsOneWidget);
    expect(find.textContaining('মিরপুর ১০'), findsOneWidget);

    // The due — pulled onto the device all along and read by nothing.
    expect(find.text('বকেয়া ৳8,125.5'), findsOneWidget);
    expect(find.text('15 দিন'), findsOneWidget);
  });

  testWidgets('the customer search matches the English name too',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: CustomerListScreen()));
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'rahim');
    await tester.pump();

    expect(find.text('রহিম স্টোর'), findsOneWidget);
    expect(find.text('কোনো মিল পাওয়া যায়নি'), findsNothing);
  });

  testWidgets('the product list draws the selling price', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: ProductListScreen()));
    await tester.pump();

    expect(find.text('লাইফবয় সাবান ১০০গ্রাম'), findsOneWidget);
    // The line that never drew at all: the screen read `salesPrice`, the
    // server sends `salePrice`, so the catalogue showed no price whatsoever.
    expect(find.text('৳42.5'), findsOneWidget);
    expect(find.textContaining('পিস'), findsWidgets);
    // Sellable stock, joined from the StockOnHand record by product id.
    expect(find.text('বিক্রয়যোগ্য 40 পিস'), findsOneWidget);
  });

  testWidgets('a role without inventory.cost.view sees no cost line',
      (tester) async {
    // docs/Contract §৩ rule ঙ: the key is absent, never null and never
    // masked. The payload seeded above has no purchasePrice.
    await tester.pumpWidget(const MaterialApp(home: ProductListScreen()));
    await tester.pump();

    expect(find.textContaining('ক্রয়:'), findsNothing);
  });

  testWidgets('the stock screen draws the quantity and where the rest went',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: StockListScreen()));
    await tester.pump();

    // The name comes from the cached Product — the stock row carries none.
    expect(find.text('লাইফবয় সাবান ১০০গ্রাম'), findsOneWidget);
    expect(find.text('40 পিস'), findsOneWidget);

    // 100 on the shelf, 40 sellable: the rep is told where the other 60 are,
    // which is the reason StockOnHandSync sends all five figures.
    expect(find.text('তাকে 100 পিস'), findsOneWidget);
    expect(find.text('অর্ডারে 55 পিস'), findsOneWidget);
    expect(find.text('আটকানো 5 পিস'), findsOneWidget);
  });

  testWidgets('a confirmed order finally shows its number', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: OrderListScreen()));
    await tester.pump();

    // The owner's second decision (docs/Contract §০): the number cannot be
    // given offline, so this is where the rep gets it. The screen read
    // `orderNumber`; the server sends `documentNo`.
    expect(find.text('SO-2609-0042'), findsOneWidget);
    expect(find.text('নম্বর নেই'), findsNothing);

    expect(find.textContaining('রহিম স্টোর'), findsOneWidget);
    expect(find.textContaining('নিশ্চিত'), findsOneWidget);
    expect(find.text('৳1,275'), findsOneWidget);
  });

  testWidgets('the order screen picks a named shop and shows what it owes',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: NewOrderScreen()));
    await tester.pump();

    await tester.tap(find.text('গ্রাহক বাছুন'));
    await tester.pumpAndSettle();

    // The picker row. Before the fix `labelOf` read `name`, so every row in
    // this sheet was blank — a rep could not tell which shop they were
    // choosing, on the screen where an order is written.
    expect(find.text('রহিম স্টোর'), findsOneWidget);

    await tester.tap(find.text('রহিম স্টোর'));
    await tester.pumpAndSettle();

    // Chosen, and the due appears before anything is promised — the figure
    // docs/Contract §০ names as unknowable offline, which is exactly why the
    // server works to keep it fresh on the device.
    expect(find.text('বকেয়া ৳8,125.5'), findsOneWidget);
    expect(find.textContaining('সীমা ৳50,000'), findsOneWidget);
  });

  testWidgets('a product added to the cart carries its price', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: NewOrderScreen()));
    await tester.pump();

    await tester.tap(find.text('যোগ করুন'));
    await tester.pumpAndSettle();

    expect(find.text('লাইফবয় সাবান ১০০গ্রাম'), findsOneWidget);
    await tester.tap(find.text('লাইফবয় সাবান ১০০গ্রাম'));
    await tester.pumpAndSettle();

    // One line at 42.5, and the estimated total that follows from it. A cart
    // whose prices are null adds up to zero and says so out loud.
    expect(find.textContaining('৳42.5 × 1'), findsOneWidget);
    expect(find.textContaining('মোট (আনুমানিক): ৳42.5'), findsOneWidget);
  });
}
