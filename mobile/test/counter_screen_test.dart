import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/widgets/confirm_overview_sheet.dart';
import 'package:abos_mobile/features/direct_sale/counter_screen.dart';
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
        warehouseId: 'wh-1',
        warehouses: [CounterChoice('wh-1', 'প্রধান গুদাম')],
        paymentTerms: [
          CounterChoice('cash', 'নগদ'),
          CounterChoice('credit:30', '৩০ দিন বাকি')
        ],
        moneyAccounts: [
          CounterChoice('ac-1', '1101 · নগদ', kind: 'cash'),
          CounterChoice('ac-2', '1102 · ব্যাংক', kind: 'bank')
        ],
        depositMethods: [
          CounterMethod(
              id: 'm-cash', label: 'নগদ', kind: 'cash', accountId: 'ac-1')
        ],
        carriers: [CounterChoice('car-1', 'করিম পরিবহন')],
        voidReasons:
            reasonList ? const ['গ্রাহক কিনবেন না', 'অন্য কারণ'] : const [],
        lots: {
          'prd-lot': [
            CounterLot(
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
    sentCustomer = customerId;
    sentTerm = paymentTerm;
    sent = lines;
    sentDraft = draft;
    sentExtras = extras;
    return answer;
  }

  @override
  Future<CounterPrice> price(String productId, {String? warehouseId}) async =>
      CounterPrice(
        name: 'কসমস বিস্কুট',
        rate: '40.00',
        unit: 'পিস',
        available: stockShown,
        lots: const [
          CounterLot(id: 'lot-a', no: 'LOT-A', expiry: '2027-01-01', qty: '50')
        ],
      );

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

Future<void> _confirm(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('counter-confirm')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('overview-confirm')));
  await tester.pumpAndSettle();
}

void main() {
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
