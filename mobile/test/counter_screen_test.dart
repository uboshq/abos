import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/widgets/confirm_overview_sheet.dart';
import 'package:abos_mobile/features/direct_sale/counter_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// সরাসরি বিক্রয়ের কাউন্টার, ফোনে (0.4.9) — মালিকের কাউন্টারের নিয়ম; দেয়াল সার্ভারের, ফোন সত্যি করে দেখায়।
class _FakeApi implements DirectSaleApi {
  List<CounterLine>? sent;
  bool? sentDraft;
  String? sentCustomer;
  String? sentTerm;
  Object? failWith;
  CounterResult answer = const CounterResult(status: 'done', notice: 'চালান S-0007 আর বিল S-0007 হলো।');
  int overviews = 0;
  bool overLimit = false;

  @override
  Future<ConfirmOverviewData> overview({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  }) async {
    overviews++;
    return ConfirmOverviewData.fromJson({
      'title': 'বিক্রির সারাংশ',
      'head': [
        {'label': 'ক্রেতা', 'value': 'রহিম স্টোর'},
      ],
      'lines': [
        {'title': 'কসমস বিস্কুট', 'details': ['লট: LOT-A', '10 × 40.00'], 'amount': '400.00'},
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
  Future<CounterSetup> setup({String? warehouseId}) async => const CounterSetup(
        warehouseId: 'wh-1',
        warehouses: [CounterChoice('wh-1', 'প্রধান গুদাম')],
        paymentTerms: [CounterChoice('cash', 'নগদ'), CounterChoice('credit:30', '৩০ দিন বাকি')],
        moneyAccounts: [CounterChoice('ac-1', '1101 · নগদ', kind: 'cash')],
        lots: {
          'prd-lot': [CounterLot(id: 'lot-a', no: 'LOT-A', expiry: '2027-01-01', qty: '50')],
        },
      );

  @override
  Future<int?> freeAllowed({required String productId, String? warehouseId, required int qty, String? lotId}) async =>
      qty >= 10 ? 1 : 0;

  @override
  Future<CounterResult> sell({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  }) async {
    if (failWith != null) throw failWith!;
    sentCustomer = customerId;
    sentTerm = paymentTerm;
    sent = lines;
    sentDraft = draft;
    return answer;
  }
}

const _dealer = CustomerRecord({'id': 'cus-1', 'nameBn': 'রহিম স্টোর'});
const _lotted = ProductRecord({'id': 'prd-lot', 'nameBn': 'কসমস বিস্কুট', 'salePrice': '40'});
const _plain = ProductRecord({'id': 'prd-plain', 'nameBn': 'সাবান', 'salePrice': '0'});

Future<void> _pump(WidgetTester tester, _FakeApi api) async {
  tester.view.physicalSize = const Size(800, 3000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
      home: CounterScreen(api: api, customers: const [_dealer], products: const [_lotted, _plain])));
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

void main() {
  testWidgets('a lotted product brings its FEFO lot, the product price and the scheme free; one product-lot is one row',
      (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await _pickProduct(tester, 'কসমস বিস্কুট');
    expect(find.byKey(const ValueKey('counter-lot')), findsOneWidget, reason: 'লট-ধরা পণ্যে লটের ঘর নেই');
    await _add(tester, qty: '10');
    expect(find.text('লট: LOT-A'), findsOneWidget);
    expect(find.text('ফ্রি: 1'), findsOneWidget, reason: 'স্কিমের ফ্রি নিজে বসেনি');

    // একই পণ্য-লট আবার — দ্বিতীয় সারি নয়, একই সারি হালনাগাদ
    await _pickProduct(tester, 'কসমস বিস্কুট');
    await _add(tester, qty: '12');
    expect(find.text('লট: LOT-A'), findsOneWidget, reason: '⛔ একই পণ্য দুই সারিতে');
    expect(find.text('12 × ৳ 40'), findsOneWidget);
  });

  testWidgets('a zero price never enters the cart', (tester) async {
    final api = _FakeApi();
    await _pump(tester, api);

    await _pickProduct(tester, 'সাবান');
    await _add(tester, qty: '5');
    expect(find.text('দর ছাড়া বিক্রি হয় না।'), findsOneWidget);
    expect(find.byKey(const ValueKey('counter-total')), findsNothing, reason: '⛔ শূন্য দরের সারি কার্টে ঢুকল');
  });

  testWidgets('a cart row is read-only: edit loads it up and the button says update', (tester) async {
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

  testWidgets('confirm sends the dealer, the term and the lines; the server answer shows in a popup', (tester) async {
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
    expect(api.sentDraft, isNull, reason: '⛔ সারাংশ দেখানোর আগেই বিক্রি হয়ে গেল');
    await tester.tap(find.byKey(const ValueKey('overview-confirm')));
    await tester.pumpAndSettle();

    expect(api.sentCustomer, 'cus-1');
    expect(api.sentDraft, isFalse);
    expect(api.sentTerm, 'cash');
    expect(api.sent!.single.toJson(), {'product': 'prd-lot', 'lot': 'lot-a', 'qty': 10, 'rate': '40.00', 'free_qty': 1});
    expect(find.byKey(const ValueKey('counter-popup')), findsOneWidget);
    expect(find.text('চালান S-0007 আর বিল S-0007 হলো।'), findsOneWidget);
  });

  testWidgets('a draft goes as a draft, and a refusal from the server (credit) is a popup, not a crash', (tester) async {
    final api = _FakeApi()..answer = const CounterResult(status: 'parked', notice: 'খসড়া রাখা হলো।');
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

  testWidgets('from the overview, go back sends nothing; over the limit confirm is off but a draft goes', (tester) async {
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
    final confirm = tester.widget<FilledButton>(find.byKey(const ValueKey('overview-confirm')));
    expect(confirm.onPressed, isNull, reason: '⛔ সীমা পার, তবু সারাংশের "নিশ্চিত" চালু');

    await tester.tap(find.byKey(const ValueKey('overview-back')));
    await tester.pumpAndSettle();
    expect(api.sentDraft, isNull, reason: '⛔ "ফিরে যান" চাপতেই কিছু পাঠানো হলো');

    await tester.tap(find.byKey(const ValueKey('counter-confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('overview-draft')));
    await tester.pumpAndSettle();
    expect(api.sentDraft, isTrue);
    expect(api.overviews, 2);
  });
}
