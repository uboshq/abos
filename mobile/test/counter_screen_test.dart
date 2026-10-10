import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/widgets/confirm_overview_sheet.dart';
import 'package:abos_mobile/features/direct_sale/counter_screen.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'return_screen_test.dart' show FakeReturnApi;

/// সরাসরি বিক্রয়ের কাউন্টার, ফোনে (0.4.9) — মালিকের কাউন্টারের নিয়ম; দেয়াল সার্ভারের, ফোন সত্যি করে দেখায়।
class _FakeApi implements DirectSaleApi {
  List<CounterLine>? sent;
  bool? sentDraft;
  String? sentCustomer;
  String? sentTerm;
  Object? failWith;
  CounterResult answer = const CounterResult(
      status: 'done', notice: 'চালান S-0007 আর বিল S-0007 হলো।');
  int overviews = 0;
  bool overLimit = false;
  CounterExtras? sentExtras;
  String? voidedReason;
  String? voidedResume;
  bool reasonList = false;
  String? stockShown = '42';

  // ⭐ 0.4.15 — দুবার-বিল, ক্রেতার দাম, গুদাম নেই
  bool duplicateOnce = false;
  int sells = 0;
  String? priceCustomer;
  // ⓘ পুরনো পরীক্ষাগুলোর দর বদলায় না — নিজের পরীক্ষা নিজের দর বসায়
  String customerRate = '40.00';
  bool priceOffline = false;
  bool noWarehouse = false;

  @override
  Future<ConfirmOverviewData> overview({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    CounterExtras extras = const CounterExtras(),
  }) async {
    overviews++;
    return ConfirmOverviewData.fromJson({
      'title': 'বিক্রির সারাংশ',
      'head': [
        {'label': 'ক্রেতা', 'value': 'রহিম স্টোর'},
      ],
      'lines': [
        {
          'title': 'কসমস বিস্কুট',
          'details': ['লট: LOT-A', '10 × 40.00'],
          'amount': '400.00'
        },
      ],
      'totals': [
        {'label': 'নিট বিল', 'amount': '400.00', 'strong': true},
      ],
      'money': const [],
      'notes': [
        if (overLimit) {'text': 'সীমা পার — ৳ 300.00', 'tone': 'stop'},
      ],
      'blocks': overLimit,
    });
  }

  @override
  Future<CounterSetup> setup({String? warehouseId}) async => CounterSetup(
        warehouseId: noWarehouse ? null : 'wh-1',
        warehouses: noWarehouse
            ? const []
            : [const CounterChoice('wh-1', 'প্রধান গুদাম')],
        paymentTerms: [
          const CounterChoice('cash', 'নগদ'),
          const CounterChoice('credit:30', '৩০ দিন বাকি')
        ],
        moneyAccounts: [
          const CounterChoice('ac-1', '1101 · নগদ', kind: 'cash'),
          const CounterChoice('ac-2', '1102 · ব্যাংক', kind: 'bank')
        ],
        depositMethods: [
          const CounterMethod(
              id: 'm-cash', label: 'নগদ', kind: 'cash', accountId: 'ac-1')
        ],
        carriers: [const CounterChoice('car-1', 'করিম পরিবহন')],
        farePayers: [const CounterChoice('u-7', 'রহিম (ক্যাশিয়ার)')],
        voidReasons:
            reasonList ? const ['গ্রাহক কিনবেন না', 'অন্য কারণ'] : const [],
        lots: noWarehouse
            ? const {}
            : {
                'prd-lot': [
                  const CounterLot(
                      id: 'lot-a', no: 'LOT-A', expiry: '2027-01-01', qty: '50')
                ],
              },
      );

  @override
  Future<int?> freeAllowed(
          {required String productId,
          String? warehouseId,
          required int qty,
          String? lotId}) async =>
      qty >= 10 ? 1 : 0;

  @override
  Future<CounterResult> sell({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    CounterExtras extras = const CounterExtras(),
  }) async {
    if (failWith != null) throw failWith!;
    sells++;
    // ⓘ সার্ভারের দেয়াল — একই বিল আগে হয়েছে, জেনেশুনে না পাঠালে ৪২২
    if (duplicateOnce && !extras.confirmDuplicate) {
      throw DioException(
        requestOptions: RequestOptions(path: '/sales/direct'),
        response: Response(
          requestOptions: RequestOptions(path: '/sales/direct'),
          statusCode: 422,
          data: {
            'message': 'আজ এই ক্রেতার ঠিক এই বিল আগেই হয়েছে।',
            'errors': {
              'confirm_duplicate': [
                'আজ এই ক্রেতার ঠিক এই বিল আগেই হয়েছে (S-0006)।'
              ]
            },
          },
        ),
      );
    }
    sentCustomer = customerId;
    sentTerm = paymentTerm;
    sent = lines;
    sentDraft = draft;
    sentExtras = extras;
    return answer;
  }

  @override
  Future<CounterPrice> price(String productId,
      {String? warehouseId, String? customerId}) async {
    priceCustomer = customerId;
    if (priceOffline) {
      throw DioException(
          requestOptions: RequestOptions(path: '/sales/direct/price'),
          type: DioExceptionType.connectionError);
    }
    return CounterPrice(
      name: 'কসমস বিস্কুট',
      rate: customerId == null ? '40.00' : customerRate,
      priceLabel: customerId == null ? '' : 'দামের তালিকা: ডিলার দর',
      unit: 'পিস',
      available: stockShown,
      lots: const [
        CounterLot(id: 'lot-a', no: 'LOT-A', expiry: '2027-01-01', qty: '50')
      ],
    );
  }

  @override
  Future<List<CounterDraftSummary>> drafts() async => const [
        CounterDraftSummary(
            id: 'drf-1',
            no: 'DRF-0014',
            customer: 'রহিম স্টোর',
            total: '400.00',
            lines: 1),
      ];

  @override
  Future<CounterDraft> openDraft(String id) async => const CounterDraft(
        id: 'drf-1',
        no: 'DRF-0014',
        customerId: 'cus-1',
        customerName: 'রহিম স্টোর',
        lines: [
          CounterLine(
              productId: 'prd-lot',
              productName: 'কসমস বিস্কুট',
              lotId: 'lot-a',
              lotNo: 'LOT-A',
              qty: 10,
              rate: 40)
        ],
      );

  @override
  Future<void> voidBill(
      {required String reason,
      String? customerId,
      String? resumeId,
      int lines = 0,
      double total = 0}) async {
    voidedReason = reason;
    voidedResume = resumeId;
  }
}

const _dealer = CustomerRecord({'id': 'cus-1', 'nameBn': 'রহিম স্টোর'});
const _lotted = ProductRecord(
    {'id': 'prd-lot', 'nameBn': 'কসমস বিস্কুট', 'salePrice': '40'});
const _plain =
    ProductRecord({'id': 'prd-plain', 'nameBn': 'সাবান', 'salePrice': '0'});

Future<void> _pump(WidgetTester tester, _FakeApi api) async {
  tester.view.physicalSize = const Size(800, 3000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
      home: CounterScreen(
          api: api,
          returnApi: FakeReturnApi(),
          customers: const [_dealer],
          products: const [_lotted, _plain])));
  await tester.pumpAndSettle();
}

Future<void> _pickProduct(WidgetTester tester, String name) async {
  await tester.tap(find.byKey(const ValueKey('counter-product')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(name).last);
  await tester.pumpAndSettle();
}

Future<void> _add(WidgetTester tester, {required String qty}) async {
  await tester.enterText(find.byKey(const ValueKey('counter-qty')), qty);
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('counter-add')));
  await tester.pumpAndSettle();
}

Future<void> _pickDealer(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('counter-customer')));
  await tester.pumpAndSettle();
  await tester.tap(find.text('রহিম স্টোর').last);
  await tester.pumpAndSettle();
}

String _rateText(WidgetTester tester) => tester
    .widget<TextField>(find.byKey(const ValueKey('counter-rate')))
    .controller!
    .text;

Future<void> _confirm(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('counter-confirm')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('overview-confirm')));
  await tester.pumpAndSettle();
}

void main() {
  test('a lot without its stock (no stock key) says nothing about how much, never "0"', () {
    // ⛔ সার্ভার মজুদের চাবি ছাড়া পরিমাণ পাঠায় না (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬)
    expect(CounterLot.fromJson(const {'id': 'l1', 'no': 'LOT-1', 'expiry': '2027-01-01', 'qty': null}).qty, '');
    expect(CounterLot.fromJson(const {'id': 'l1', 'no': 'LOT-1', 'expiry': '2027-01-01', 'qty': '12'}).qty, '12');
  });

  // ── ⭐ 0.4.15 — সমন্বয়কের তিন কাজ, ৬ অক্টোবর ২০২৬ ───────────────────────────────

  testWidgets(
      'the same bill twice: the server warning shows, "again" needs the tick, and only then confirm_duplicate goes',
      (tester) async {
    final api = _FakeApi()..duplicateOnce = true;
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    await _confirm(tester);

    expect(find.byKey(const ValueKey('counter-repeat')), findsOneWidget,
        reason: '⛔ দুবার-বিলের সতর্কতা সাধারণ ভুলের পপ-আপে হারাল');
    expect(find.textContaining('S-0006'), findsOneWidget,
        reason: 'সার্ভারের বার্তা (আগের বিলের নম্বরসহ) দেখা গেল না');
    expect(api.sent, isNull);

    // ⓘ টিক ছাড়া "আবার করুন" চাপা যায় না
    await tester.tap(find.byKey(const ValueKey('counter-repeat-go')));
    await tester.pumpAndSettle();
    expect(api.sells, 1, reason: '⛔ টিক ছাড়াই আবার পাঠানো হলো');

    await tester.tap(find.byKey(const ValueKey('counter-repeat-tick')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-repeat-go')));
    await tester.pumpAndSettle();

    expect(api.sells, 2);
    expect(api.sentExtras!.confirmDuplicate, isTrue);
    expect(find.text('বিক্রি হলো'), findsOneWidget);
  });

  testWidgets(
      'the same bill twice: going back sends nothing more and keeps the cart',
      (tester) async {
    final api = _FakeApi()..duplicateOnce = true;
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    await _confirm(tester);

    await tester.tap(find.text('ফিরে যান'));
    await tester.pumpAndSettle();
    expect(api.sells, 1);
    expect(api.sent, isNull);
    expect(find.text('লট: LOT-A'), findsOneWidget,
        reason: '⛔ ফিরে গেলে কার্ট মুছে গেল');
  });

  testWidgets(
      'the customer\'s price list rate comes in over the stored sale price',
      (tester) async {
    final api = _FakeApi()..customerRate = '37.50';
    await _pump(tester, api);

    // ⓘ ক্রেতা ছাড়া — ফোনের জমা দাম
    await _pickProduct(tester, 'কসমস বিস্কুট');
    expect(_rateText(tester), '40.00');
    expect(api.priceCustomer, isNull);

    // ⓘ ক্রেতা বাছলে — সেই ক্রেতার দাম, কোথা থেকে তা-ও
    await _pickDealer(tester);
    expect(api.priceCustomer, 'cus-1');
    expect(_rateText(tester), '37.50', reason: '⛔ দামের তালিকার দর বসল না');
    expect(find.text('দামের তালিকা: ডিলার দর'), findsOneWidget);

    await _add(tester, qty: '10');
    await _confirm(tester);
    expect(api.sent!.single.rate, 37.5);
  });

  testWidgets('customer first, then the product: the customer\'s rate too',
      (tester) async {
    final api = _FakeApi()..customerRate = '37.50';
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');

    expect(_rateText(tester), '37.50',
        reason: '⛔ ক্রেতা আগে বাছলে পণ্যের দর ফোনের জমা দামেই রইল');
  });

  testWidgets('a hand-typed rate is never overwritten by the late answer',
      (tester) async {
    final api = _FakeApi()..customerRate = '37.50';
    await _pump(tester, api);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await tester.enterText(find.byKey(const ValueKey('counter-rate')), '39');
    await tester.pumpAndSettle();

    await _pickDealer(tester);
    expect(_rateText(tester), '39',
        reason: '⛔ মানুষের লেখা দর সার্ভারের দর মুছে দিল');
  });

  testWidgets('offline, the stored sale price stays and nothing breaks',
      (tester) async {
    final api = _FakeApi()
      ..customerRate = '37.50'
      ..priceOffline = true;
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');

    expect(_rateText(tester), '40.00');
    expect(find.byKey(const ValueKey('counter-error')), findsNothing,
        reason: 'অফলাইনে দাম না আসা ভুল নয় — আগের দামই চলে');
  });

  testWidgets(
      'no warehouse: a plain message instead of empty lots, and nothing goes into the cart',
      (tester) async {
    final api = _FakeApi()..noWarehouse = true;
    await _pump(tester, api);

    expect(find.byKey(const ValueKey('counter-no-warehouse')), findsOneWidget);
    expect(
        find.textContaining('কোনো চালু গুদাম পাওয়া যায়নি'), findsOneWidget);

    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    expect(find.text('লট: LOT-A'), findsNothing);
    expect(
        tester.widget<Text>(find.byKey(const ValueKey('counter-error'))).data,
        contains('গুদাম ছাড়া লট আসে না'),
        reason: '⛔ গুদাম ছাড়াই লট-ধরা পণ্য কার্টে ঢুকল');
  });

  // ── ⭐ ওয়েবের কাউন্টারের ৮ বোতাম, ফোনেও — মালিক, ৪ অক্টোবর ২০২৬ ─────────────────

  testWidgets(
      'take money: two payments in one bill go to the server, each with its account and way',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-money')));
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const ValueKey('counter-money-amount')), '300');
    await tester.tap(find.byKey(const ValueKey('counter-money-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const ValueKey('counter-money-amount')), '100');
    await tester.tap(find.byKey(const ValueKey('counter-money-save')));
    await tester.pumpAndSettle();

    expect(find.text('নেওয়া টাকা: ৳ 400'), findsOneWidget);
    await _confirm(tester);

    final deposits = api.sentExtras!.deposits;
    expect(deposits, hasLength(2), reason: '⛔ দুই রকম টাকা এক বিলে গেল না');
    expect(deposits.map((d) => d.amount), [300, 100]);
    expect(deposits.first.toJson(),
        {'account': 'ac-1', 'amount': '300.00', 'method': 'm-cash'});
  });

  testWidgets(
      'delivery and vehicle: the chosen mode, owner, fare and who pays reach the server',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-delivery')));
    await tester.pumpAndSettle();
    await tester
        .tap(find.byKey(const ValueKey('counter-delivery-pickup_later')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-delivery-save')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('counter-vehicle')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-vehicle-owner')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ভাড়ার গাড়ি').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('counter-fare')), '150');
    await tester.tap(find.byKey(const ValueKey('counter-fare-paid-by')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ক্রেতা চালককে দেবেন').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-vehicle-save')));
    await tester.pumpAndSettle();

    await _confirm(tester);
    expect(api.sentExtras!.delivery.toJson(), {
      'delivery_mode': 'pickup_later',
      'vehicle_owner': 'hired',
      'transport_cost': '150.00',
      'fare_paid_by': 'customer',
    });
  });

  /// ⭐ ভাড়া আমরা দিলে — কখন, কোন খাত, TrxID আর কে দিলেন (মালিক, ৭ অক্টোবর ২০২৬; a5, সার্ভার 272141b9)
  Future<void> fareSheet(WidgetTester tester, {required String when, String? account, String? reference, bool payer = false, bool carrier = false}) async {
    await tester.tap(find.byKey(const ValueKey('counter-vehicle')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-vehicle-owner')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('আমাদের গাড়ি').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('counter-fare')), '200');
    await tester.tap(find.byKey(const ValueKey('counter-fare-paid-by')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('আমরা — আমাদের খরচ').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text(when == 'now' ? 'এখনই দিলাম' : 'পরে দেব'));
    await tester.pumpAndSettle();
    if (account != null) {
      await tester.tap(find.byKey(const ValueKey('counter-fare-account')));
      await tester.pumpAndSettle();
      await tester.tap(find.text(account).last);
      await tester.pumpAndSettle();
    }
    if (reference != null) {
      await tester.enterText(find.byKey(const ValueKey('counter-fare-reference')), reference);
    }
    if (payer) {
      await tester.tap(find.byKey(const ValueKey('counter-fare-payer')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('রহিম (ক্যাশিয়ার)').last);
      await tester.pumpAndSettle();
    }
    if (carrier) {
      await tester.tap(find.byKey(const ValueKey('counter-fare-carrier')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('করিম পরিবহন').last);
      await tester.pumpAndSettle();
    }
    await tester.ensureVisible(find.byKey(const ValueKey('counter-vehicle-save')));
    await tester.tap(find.byKey(const ValueKey('counter-vehicle-save')));
    await tester.pumpAndSettle();
  }

  testWidgets('a fare we pay now from a bank sends the account, the TrxID and who paid', (tester) async {
    final api = _FakeApi();
    tester.view.physicalSize = const Size(900, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    await fareSheet(tester, when: 'now', account: '1102 · ব্যাংক', reference: 'TRX-9001', payer: true);
    await _confirm(tester);
    expect(api.sentExtras!.delivery.toJson(), {
      'vehicle_owner': 'own', 'transport_cost': '200.00', 'fare_paid_by': 'us',
      'fare_when': 'now', 'fare_account': 'ac-2', 'fare_reference': 'TRX-9001', 'fare_payer': 'u-7',
    });
  });

  testWidgets('a fare paid now in cash sends no TrxID and no payer', (tester) async {
    tester.view.physicalSize = const Size(900, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = _FakeApi();
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    await fareSheet(tester, when: 'now', account: '1101 · নগদ');
    expect(find.byKey(const ValueKey('counter-fare-reference')), findsNothing);
    await _confirm(tester);
    expect(api.sentExtras!.delivery.toJson(), {
      'vehicle_owner': 'own', 'transport_cost': '200.00', 'fare_paid_by': 'us', 'fare_when': 'now', 'fare_account': 'ac-1',
    });
  });

  testWidgets('a fare we pay later sends the carrier it is owed to', (tester) async {
    tester.view.physicalSize = const Size(900, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = _FakeApi();
    await _pump(tester, api);
    await _pickDealer(tester);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    await fareSheet(tester, when: 'later', carrier: true);
    await _confirm(tester);
    expect(api.sentExtras!.delivery.toJson(), {
      'vehicle_owner': 'own', 'carrier': 'car-1', 'transport_cost': '200.00', 'fare_paid_by': 'us', 'fare_when': 'later',
    });
  });

  testWidgets(
      'open a kept draft: its customer and lines come back, and confirming finishes that same draft',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await tester.tap(find.byKey(const ValueKey('counter-open-draft')));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('DRF-0014').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('counter-resume-banner')), findsOneWidget);
    expect(find.text('লট: LOT-A'), findsOneWidget);
    expect(find.text('রহিম স্টোর'), findsWidgets);

    await _confirm(tester);
    expect(api.sentExtras!.resumeId, 'drf-1',
        reason: '⛔ খসড়া খুলে পাঠালে নতুন বিল হত');
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('counter-resume-banner')), findsNothing);
  });

  testWidgets(
      'void needs a reason, cancels the open draft and clears the counter',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);
    await tester.tap(find.byKey(const ValueKey('counter-open-draft')));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('DRF-0014').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('counter-void')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-void-confirm')));
    await tester.pumpAndSettle();
    expect(api.voidedReason, isNull, reason: '⛔ কারণ ছাড়াই বাতিল হয়ে গেল');

    await tester.enterText(
        find.byKey(const ValueKey('counter-void-reason')), 'ভুল ক্রেতা');
    await tester.tap(find.byKey(const ValueKey('counter-void-confirm')));
    await tester.pumpAndSettle();

    expect(api.voidedReason, 'ভুল ক্রেতা');
    expect(api.voidedResume, 'drf-1');
    expect(find.text('বিল বাতিল হলো'), findsOneWidget);
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();
    expect(find.text('লট: LOT-A'), findsNothing,
        reason: '⛔ বাতিলের পরেও কার্টে সারি রয়ে গেল');
  });

  // ⭐ দাম দেখুন — দর, মজুদ (চাবি থাকলে), লট; "বিলে তুলুন" উপরের ঘরে বসায়
  testWidgets(
      'price check shows the rate and stock, hides stock without the key, and puts the product on the bill',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await tester.tap(find.byKey(const ValueKey('counter-price')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('কসমস বিস্কুট').last);
    await tester.pumpAndSettle();
    expect(find.text('বিক্রয় দর: ৳ 40.00 / পিস'), findsOneWidget);
    expect(find.text('বিক্রয়যোগ্য মজুদ: 42 পিস'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('counter-price-to-bill')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('counter-lot')), findsOneWidget,
        reason: 'the product sits in the entry box');

    api.stockShown = null;
    await tester.tap(find.byKey(const ValueKey('counter-price')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('কসমস বিস্কুট').last);
    await tester.pumpAndSettle();
    expect(find.text('বিক্রয়যোগ্য মজুদ: দেখার অনুমতি নেই'), findsOneWidget,
        reason: '⛔ চাবি ছাড়া মজুদ দেখা গেল');
  });

  testWidgets('void reasons come from the web list when the server sends one',
      (tester) async {
    final api = _FakeApi()..reasonList = true;
    await _pump(tester, api);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-void')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-void-reason-pick')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('গ্রাহক কিনবেন না').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('counter-void-confirm')));
    await tester.pumpAndSettle();

    expect(api.voidedReason, 'গ্রাহক কিনবেন না');
  });

  testWidgets(
      'reprint before any bill says so; return opens the return screen on the bills of this customer',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await tester.tap(find.byKey(const ValueKey('counter-reprint')));
    await tester.pumpAndSettle();
    expect(find.textContaining('এখনো কোনো বিল হয়নি'), findsOneWidget);
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('counter-return')));
    await tester.pumpAndSettle();
    expect(find.text('বিক্রি ফেরত'), findsOneWidget);
    expect(find.text('INV-0007'), findsOneWidget,
        reason: 'the bills to return from');
  });

  testWidgets(
      'a lotted product brings its FEFO lot, the product price and the scheme free; one product-lot is one row',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await _pickProduct(tester, 'কসমস বিস্কুট');
    expect(find.byKey(const ValueKey('counter-lot')), findsOneWidget,
        reason: 'লট-ধরা পণ্যে লটের ঘর নেই');
    await _add(tester, qty: '10');
    expect(find.text('লট: LOT-A'), findsOneWidget);
    expect(find.text('ফ্রি: 1'), findsOneWidget,
        reason: 'স্কিমের ফ্রি নিজে বসেনি');

    // একই পণ্য-লট আবার — দ্বিতীয় সারি নয়, একই সারি হালনাগাদ
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '12');
    expect(find.text('লট: LOT-A'), findsOneWidget,
        reason: '⛔ একই পণ্য দুই সারিতে');
    expect(find.text('12 × ৳ 40'), findsOneWidget);
  });

  testWidgets('a zero price never enters the cart', (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await _pickProduct(tester, 'সাবান');
    await _add(tester, qty: '5');
    expect(find.text('দর ছাড়া বিক্রি হয় না।'), findsOneWidget);
    expect(find.byKey(const ValueKey('counter-total')), findsNothing,
        reason: '⛔ শূন্য দরের সারি কার্টে ঢুকল');
  });

  testWidgets(
      'a cart row is read-only: edit loads it up and the button says update',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-edit-prd-lot|lot-a')));
    await tester.pumpAndSettle();
    expect(find.text('হালনাগাদ করুন'), findsOneWidget);
    await _add(tester, qty: '20');
    expect(find.text('20 × ৳ 40'), findsOneWidget);
    expect(find.text('কার্টে যোগ করুন'), findsOneWidget);
  });

  testWidgets(
      'confirm sends the dealer, the term and the lines; the server answer shows in a popup',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await tester.tap(find.byKey(const ValueKey('counter-customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('রহিম স্টোর').last);
    await tester.pumpAndSettle();
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-confirm')));
    await tester.pumpAndSettle();

    // ⭐ আগে সারাংশ — তখনো বিক্রি নয়
    expect(find.byKey(const ValueKey('overview-sheet')), findsOneWidget);
    expect(find.text('নিট বিল: ৳ 400.00'), findsOneWidget);
    expect(api.sentDraft, isNull,
        reason: '⛔ সারাংশ দেখানোর আগেই বিক্রি হয়ে গেল');
    await tester.tap(find.byKey(const ValueKey('overview-confirm')));
    await tester.pumpAndSettle();

    expect(api.sentCustomer, 'cus-1');
    expect(api.sentDraft, isFalse);
    expect(api.sentTerm, 'cash');
    expect(api.sent!.single.toJson(), {
      'product': 'prd-lot',
      'lot': 'lot-a',
      'qty': 10,
      'rate': '40.00',
      'free_qty': 1
    });
    expect(find.byKey(const ValueKey('counter-popup')), findsOneWidget);
    expect(find.text('চালান S-0007 আর বিল S-0007 হলো।'), findsOneWidget);
  });

  testWidgets(
      'a draft goes as a draft, and a refusal from the server (credit) is a popup, not a crash',
      (tester) async {
    final api = _FakeApi()
      ..answer =
          const CounterResult(status: 'parked', notice: 'খসড়া রাখা হলো।');
    await _pump(tester, api);
    await tester.tap(find.byKey(const ValueKey('counter-customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('রহিম স্টোর').last);
    await tester.pumpAndSettle();
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-draft')));
    await tester.pumpAndSettle();
    expect(api.sentDraft, isTrue);
    expect(find.text('খসড়া রাখা হলো'), findsOneWidget);
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();

    api.failWith = Exception('ঋণসীমা');
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');
    await tester.tap(find.byKey(const ValueKey('counter-confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('overview-confirm')));
    await tester.pumpAndSettle();
    expect(find.text('বিক্রি হলো না'), findsOneWidget);
  });

  testWidgets(
      'from the overview, go back sends nothing; over the limit confirm is off but a draft goes',
      (tester) async {
    final api = _FakeApi()..overLimit = true;
    await _pump(tester, api);
    await tester.tap(find.byKey(const ValueKey('counter-customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('রহিম স্টোর').last);
    await tester.pumpAndSettle();
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '10');

    await tester.tap(find.byKey(const ValueKey('counter-confirm')));
    await tester.pumpAndSettle();
    expect(find.text('সীমা পার — ৳ 300.00'), findsOneWidget);
    final confirm = tester
        .widget<FilledButton>(find.byKey(const ValueKey('overview-confirm')));
    expect(confirm.onPressed, isNull,
        reason: '⛔ সীমা পার, তবু সারাংশের "নিশ্চিত" চালু');

    await tester.tap(find.byKey(const ValueKey('overview-back')));
    await tester.pumpAndSettle();
    expect(api.sentDraft, isNull,
        reason: '⛔ "ফিরে যান" চাপতেই কিছু পাঠানো হলো');

    await tester.tap(find.byKey(const ValueKey('counter-confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('overview-draft')));
    await tester.pumpAndSettle();
    expect(api.sentDraft, isTrue);
    expect(api.overviews, 2);
  });
}
