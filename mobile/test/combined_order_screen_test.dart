import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/orders/order_api.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/orders/new_order_screen.dart';
import 'package:abos_mobile/features/orders/order_sheets.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// The combined order screen of 0.4.3 — the owner's approved samples of
/// 1 October 2026, and his rules: no stock on the SR's phone, free goods
/// from the server, a keypad for big quantities, and পাঠান that never
/// blocks the order.

const _shop = 'c-0001';
const _soap = 'p-soap';
const _detergent = 'p-det';

class _Api implements OrderApi {
  _Api({this.standingAnswer, this.standingError, this.freeFor});

  final CustomerStanding? standingAnswer;
  final Object? standingError;

  /// (productId, qty) → free pieces the "server" answers.
  final double Function(String productId, int qty)? freeFor;

  final offerCalls = <List<OfferAsk>>[];
  final standingCalls = <double>[];

  @override
  Future<List<OfferLine>> offers(String customerId, List<OfferAsk> lines) async {
    offerCalls.add(lines);
    final free = freeFor;
    if (free == null) {
      throw DioException(
          requestOptions: RequestOptions(path: '/sales/offers'),
          type: DioExceptionType.connectionError);
    }
    return [
      for (final l in lines)
        OfferLine(
          productId: l.productId,
          freeQty: free(l.productId, l.qty),
          offer: l.productId == _soap ? '১২টা কিনলে ১টা ফ্রি' : null,
        ),
    ];
  }

  @override
  Future<CustomerStanding> standing(String customerId, double orderTotal) async {
    standingCalls.add(orderTotal);
    if (standingError != null) throw standingError!;
    return standingAnswer!;
  }
}

class _Memory extends OrderMemory {
  _Memory([this.last = const {}]);

  final Map<String, int> last;
  final remembered = <Map<String, int>>[];

  @override
  Map<String, int> lastFor(String customerId) => last;

  @override
  Future<void> remember(String customerId, Map<String, int> lines) async => remembered.add(lines);
}

const _overLimit = CustomerStanding(
  due: 48200,
  advance: 0,
  pendingClaims: 1500,
  pendingClaimCount: 2,
  limit: 50000,
  orderTotal: 4820,
  toPay: 3020,
  overLimit: true,
);

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() async {
    await ReferenceCache.instance.clearAll();
    final now = DateTime(2026, 10, 1);
    await ReferenceCache.instance.put(
      entityType: 'Customer',
      entityId: _shop,
      updatedAt: now,
      payload: const {'id': _shop, 'nameBn': 'ভাই ভাই এন্টারপ্রাইজ', 'addressBn': 'নেত্রকোনা'},
    );
    await ReferenceCache.instance.put(
      entityType: 'Product',
      entityId: _soap,
      updatedAt: now,
      payload: const {
        'id': _soap,
        'code': 'SOAP100',
        'nameBn': 'সাবান ১০০ গ্রাম',
        'barcode': '8901030',
        'salePrice': '35.0000',
        'packs': [
          {'unitNameBn': 'কার্টন', 'factor': '48', 'barcode': '18901030'},
        ],
        'isActive': true,
      },
    );
    await ReferenceCache.instance.put(
      entityType: 'Product',
      entityId: _detergent,
      updatedAt: now,
      payload: const {'id': _detergent, 'code': 'DET500', 'nameBn': 'ডিটারজেন্ট ৫০০ গ্রাম', 'salePrice': '90.0000'},
    );
    await ReferenceCache.instance.put(
      entityType: 'StockOnHand',
      entityId: _soap,
      updatedAt: now,
      payload: const {'productId': _soap, 'floor': '777.0000', 'available': '777.0000'},
    );
  });

  late List<Map<String, dynamic>> queued;

  Future<void> pumpScreen(WidgetTester tester, _Api api, {_Memory? memory, bool pickShop = true}) async {
    _phone(tester);
    queued = [];
    await tester.pumpWidget(MaterialApp(
      home: NewOrderScreen(
        api: api,
        memory: memory ?? _Memory(),
        offerDelay: const Duration(milliseconds: 10),
        enqueue: (payload) async => queued.add(payload),
      ),
    ));
    await tester.pump();
    if (!pickShop) return;
    await tester.tap(find.byKey(const ValueKey('order-customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ভাই ভাই এন্টারপ্রাইজ'));
    await tester.pumpAndSettle();
  }

  group('the keypad', () {
    testWidgets('cartons and pieces add up, and ঝুড়িতে যোগ hands the total back', (tester) async {
      _phone(tester);
      int? result = -1;
      await tester.pumpWidget(MaterialApp(
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () async => result = await showQtyKeypad(
              context,
              productName: 'সাবান ১০০ গ্রাম',
              current: 0,
              rate: 35,
              offer: '১২টা কিনলে ১টা ফ্রি',
              cartonName: 'কার্টন',
              cartonFactor: 48,
              lastQty: 1200,
            ),
            child: const Text('open'),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      expect(find.text('১ কার্টন = ৪৮ পিস'), findsOneWidget);
      expect(find.text('১২টা কিনলে ১টা ফ্রি'), findsOneWidget);
      expect(find.text('+১ কার্টন'), findsOneWidget);
      expect(find.text('+১০ কার্টন'), findsOneWidget);
      expect(find.text('আগের মতো (১,২০০)'), findsOneWidget);
      expect(find.text('ঝুড়িতে যোগ'), findsOneWidget);

      // 31 cartons and 12 pieces = 1,500 — typed, not reached with a + button.
      await tester.tap(find.byKey(const ValueKey('keypad-3')));
      await tester.tap(find.byKey(const ValueKey('keypad-1')));
      await tester.tap(find.byKey(const ValueKey('keypad-pieces')));
      await tester.tap(find.byKey(const ValueKey('keypad-1')));
      await tester.tap(find.byKey(const ValueKey('keypad-2')));
      await tester.pump();
      expect(find.text('১,৫০০'), findsOneWidget);
      expect(find.textContaining('এই লাইনে ৳৫২,৫০০'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('keypad-add')));
      await tester.pumpAndSettle();
      expect(result, 1500);
    });

    testWidgets('without a pack it is one box: 200 and 500 are typed straight in', (tester) async {
      _phone(tester);
      int? result;
      await tester.pumpWidget(MaterialApp(
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () async => result = await showQtyKeypad(context, productName: 'ডিটারজেন্ট', current: 0, rate: 90),
            child: const Text('open'),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      expect(find.textContaining('কার্টন'), findsNothing);
      for (final key in ['keypad-5', 'keypad-0', 'keypad-0']) {
        await tester.tap(find.byKey(ValueKey(key)));
      }
      await tester.tap(find.byKey(const ValueKey('keypad-back')));
      await tester.tap(find.byKey(const ValueKey('keypad-0')));
      await tester.tap(find.byKey(const ValueKey('keypad-add')));
      await tester.pumpAndSettle();
      expect(result, 500);
    });

    testWidgets('a quick chip: +১ কার্টন and আগের মতো', (tester) async {
      _phone(tester);
      int? result;
      await tester.pumpWidget(MaterialApp(
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () async => result = await showQtyKeypad(context,
                productName: 'সাবান', current: 10, cartonFactor: 48, cartonName: 'কার্টন', lastQty: 96),
            child: const Text('open'),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('+১ কার্টন'));
      await tester.tap(find.byKey(const ValueKey('keypad-add')));
      await tester.pumpAndSettle();
      expect(result, 58);

      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('আগের মতো (৯৬)'));
      await tester.tap(find.byKey(const ValueKey('keypad-add')));
      await tester.pumpAndSettle();
      expect(result, 96);
    });
  });

  testWidgets('the free box is filled by the server, and the line says ✓ ঝুড়িতে আছে', (tester) async {
    final api = _Api(freeFor: (id, qty) => id == _soap ? (qty ~/ 12).toDouble() : 0);
    await pumpScreen(tester, api);

    // The offer chip arrived from the server for the shop.
    expect(find.text('১২টা কিনলে ১টা ফ্রি'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('order-qty-$_soap')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('keypad-pieces')));
    await tester.tap(find.byKey(const ValueKey('keypad-2')));
    await tester.tap(find.byKey(const ValueKey('keypad-4')));
    await tester.tap(find.byKey(const ValueKey('keypad-add')));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(milliseconds: 20));
    await tester.pumpAndSettle();

    final free = find.byKey(const ValueKey('order-free-$_soap'));
    expect(find.descendant(of: free, matching: find.text('২')), findsOneWidget);
    expect(find.text('ফ্রি · নিজে বসেছে'), findsOneWidget);
    expect(find.text('✓ ঝুড়িতে আছে · ৳৩৫'), findsOneWidget);
    expect(find.text('বিক্রি · ৳৮৪০'), findsOneWidget);
    expect(find.text('বিক্রি ২৪ পিস · ফ্রি ২'), findsOneWidget);
    expect(find.text('৳৮৪০'), findsWidgets);
    expect(api.offerCalls.last.single.qty, 24);
  });

  testWidgets('pressing পাঠান opens the check, with the three tiles and the over-limit sentence',
      (tester) async {
    final api = _Api(standingAnswer: _overLimit, freeFor: (_, __) => 0);
    await pumpScreen(tester, api);
    await tester.tap(find.byKey(const ValueKey('order-plus-$_detergent')));
    await tester.pump(const Duration(milliseconds: 20));
    await tester.tap(find.byKey(const ValueKey('order-send')));
    await tester.pumpAndSettle();

    expect(find.text('পাঠানোর আগে দেখে নিন'), findsOneWidget);
    expect(find.text('জমা আছে'), findsOneWidget);
    expect(find.text('অনুমোদনের অপেক্ষায়'), findsOneWidget);
    expect(find.text('দিতে হবে'), findsOneWidget);
    expect(find.text('৳১,৫০০'), findsOneWidget);
    expect(find.text('৳৩,০২০'), findsOneWidget);
    expect(
      find.text('বাকির সীমা পার হচ্ছে — অর্ডার যাবে, কিন্তু ৳৩,০২০ জমা না হলে ডেলিভারি হবে না। '
          'এই সীমা কেউ পার করাতে পারেন না, মালিকও না।'),
      findsOneWidget,
    );
    expect(api.standingCalls, [90]);

    // ⛔ Never blocks: the send button is live over the limit.
    final send = tester.widget<FilledButton>(find.byKey(const ValueKey('confirm-send')));
    expect(send.onPressed, isNotNull);

    await tester.tap(find.text('ব্যাংক স্লিপ পাঠান'));
    await tester.pumpAndSettle();
    expect(find.text('শীঘ্রই আসছে'), findsOneWidget);
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('ফিরে যান'));
    await tester.pumpAndSettle();
    expect(find.text('পাঠানোর আগে দেখে নিন'), findsNothing);
    expect(find.text('✓ ঝুড়িতে আছে · ৳৯০'), findsOneWidget, reason: 'going back keeps the basket');
  });

  testWidgets('no net: the order still goes, the sheet says the figures come later', (tester) async {
    final api = _Api(
      standingError: DioException(
          requestOptions: RequestOptions(path: '/sales/standing'), type: DioExceptionType.connectionError),
    );
    final memory = _Memory();
    await pumpScreen(tester, api, memory: memory);

    await tester.tap(find.byKey(const ValueKey('order-plus-$_soap')));
    await tester.tap(find.byKey(const ValueKey('order-plus-$_soap')));
    await tester.pump(const Duration(milliseconds: 20));
    await tester.tap(find.byKey(const ValueKey('order-send')));
    await tester.pumpAndSettle();

    expect(find.text('নেট নেই — হিসাব পরে দেখা যাবে'), findsOneWidget);
    expect(find.text('জমা আছে'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('confirm-send')));
    await tester.pumpAndSettle();

    expect(queued.length, 1);
    final payload = queued.single;
    expect(payload['customerId'], _shop);
    expect(jsonEncode(payload['lines']), jsonEncode([
      {'productId': _soap, 'qty': 2, 'rate': '35.0000'},
    ]));
    expect(payload.toString().contains('free'), isFalse, reason: 'free goods are applied at the bill');
    expect(memory.remembered.single, {_soap: 2});
    expect(find.text('বিক্রি ০ পিস · ফ্রি ০'), findsOneWidget, reason: 'the basket empties');
  });

  testWidgets('offline offers: the free box keeps the last answer, or ০', (tester) async {
    final api = _Api();
    await pumpScreen(tester, api);
    await tester.tap(find.byKey(const ValueKey('order-plus-$_soap')));
    await tester.pump(const Duration(milliseconds: 20));
    await tester.pumpAndSettle();
    final free = find.byKey(const ValueKey('order-free-$_soap'));
    expect(find.descendant(of: free, matching: find.text('০')), findsOneWidget);
  });

  testWidgets('no stock figure anywhere on the screen', (tester) async {
    await pumpScreen(tester, _Api(freeFor: (_, __) => 0));
    await tester.tap(find.byKey(const ValueKey('order-plus-$_soap')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('order-qty-$_soap')));
    await tester.pumpAndSettle();

    for (final word in ['মজুদ', 'স্টক', 'বিক্রয়যোগ্য', 'তাকে', '৭৭৭', '777']) {
      expect(find.textContaining(word), findsNothing, reason: '"$word" on the SR\'s order screen');
    }
  });

  testWidgets('search by name or code, filter by offer, and the badge counts the filters', (tester) async {
    await pumpScreen(tester, _Api(freeFor: (_, __) => 0));

    await tester.enterText(find.byKey(const ValueKey('order-search')), 'DET5');
    await tester.pump();
    expect(find.text('ডিটারজেন্ট ৫০০ গ্রাম'), findsOneWidget);
    expect(find.text('সাবান ১০০ গ্রাম'), findsNothing);
    await tester.enterText(find.byKey(const ValueKey('order-search')), '');
    await tester.pump();

    await tester.tap(find.byKey(const ValueKey('order-filter')));
    await tester.pumpAndSettle();
    expect(find.text('ব্র্যান্ড'), findsNothing, reason: 'no brand in the product payload yet');
    await tester.tap(find.text('অফার আছে'));
    await tester.tap(find.text('দাম ধরে'));
    await tester.pumpAndSettle();
    expect(find.text('১টা পণ্য দেখান'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('filter-apply')));
    await tester.pumpAndSettle();

    expect(find.text('সাবান ১০০ গ্রাম'), findsOneWidget);
    expect(find.text('ডিটারজেন্ট ৫০০ গ্রাম'), findsNothing);
    expect(find.descendant(of: find.byKey(const ValueKey('order-filter')), matching: find.text('২')), findsOneWidget);

    // A chip removes its own filter.
    await tester.tap(find.descendant(
        of: find.widgetWithText(InputChip, 'অফার আছে'), matching: find.byTooltip('সরান')));
    await tester.pumpAndSettle();
    expect(find.text('ডিটারজেন্ট ৫০০ গ্রাম'), findsOneWidget);
  });

  testWidgets('"এই দোকান আগে নিয়েছে" uses what this phone ordered for the shop', (tester) async {
    await pumpScreen(tester, _Api(freeFor: (_, __) => 0), memory: _Memory({_detergent: 12}));
    await tester.tap(find.byKey(const ValueKey('order-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('এই দোকান আগে নিয়েছে'));
    await tester.tap(find.byKey(const ValueKey('filter-apply')));
    await tester.pumpAndSettle();
    expect(find.text('ডিটারজেন্ট ৫০০ গ্রাম'), findsOneWidget);
    expect(find.text('সাবান ১০০ গ্রাম'), findsNothing);
  });

  testWidgets('a carton barcode finds its product and opens the keypad', (tester) async {
    await pumpScreen(tester, _Api(freeFor: (_, __) => 0));
    await tester.tap(find.byKey(const ValueKey('order-barcode')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('barcode-field')), '18901030');
    await tester.tap(find.text('খুঁজুন'));
    await tester.pumpAndSettle();
    expect(find.text('ঝুড়িতে যোগ'), findsOneWidget);
    expect(find.text('১ কার্টন = ৪৮ পিস'), findsOneWidget);
  });

  test('offers are matched back by public_id, or by position when the server sends only its own id', () {
    const asked = [OfferAsk(productId: 'a', qty: 24), OfferAsk(productId: 'b', qty: 6)];
    final byPosition = ServerOrderApi.readOffers({
      'lines': [
        {'product_id': 12, 'qty': '24.0000', 'free_qty': '2.0000', 'offer': '১২টা কিনলে ১টা ফ্রি'},
        {'product_id': 13, 'qty': '6.0000', 'free_qty': '0.0000', 'offer': null},
      ],
    }, asked);
    expect(byPosition.map((l) => (l.productId, l.freeQty, l.offer)),
        [('a', 2.0, '১২টা কিনলে ১টা ফ্রি'), ('b', 0.0, null)]);

    final echoed = ServerOrderApi.readOffers({
      'lines': [
        {'product': 'b', 'free_qty': '1'},
      ],
    }, asked);
    expect(echoed.single.productId, 'b');
  });

  test('the standing reads the server\'s shape', () {
    final s = CustomerStanding.fromJson(const {
      'due': '48200.0000',
      'advance': '0.0000',
      'held': '0.0000',
      'limit': '50000.0000',
      'pending_claims': '1500.0000',
      'pending_claim_count': 2,
      'order_total': '4820.0000',
      'exposure': '53020.0000',
      'to_pay': '3020.0000',
      'over_limit': true,
    });
    expect([s.advance, s.pendingClaims, s.toPay, s.overLimit, s.pendingClaimCount], [0.0, 1500.0, 3020.0, true, 2]);
  });
}

/// The phone the samples were drawn for — 390 × 844.
void _phone(WidgetTester tester) {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}
