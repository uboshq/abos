import 'package:abos_mobile/core/orders/quotation_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/features/quotations/quotation_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ উদ্ধৃতি — সমন্বয়কের ক্রম "ঘ" (৫ অক্টোবর ২০২৬)। নিয়ম সার্ভারের; ফোন যা পাঠায় আর কোন বোতাম দেখায় তা-ই দাবি।
class _FakeApi implements QuotationApi {
  String? sentCustomer;
  List<QuotedLine>? sentLines;
  bool? sentSubmit;
  final List<String> calls = [];
  Quotation current = _q('approved', {'send': true});

  static Quotation _q(String status, Map<String, bool> can) => Quotation(
        id: 'q1', no: 'QTN-0001', customer: 'রহিম স্টোর', status: status, statusLabel: status, total: 400, can: can,
        lines: const [QuotationLine(product: 'কসমস বিস্কুট', qty: 10, rate: 40, amount: 400)],
      );

  @override
  Future<(List<Quotation>, int?)> list({String? status, int page = 1}) async => ([current], null);

  @override
  Future<Quotation> show(String id) async => current;

  @override
  Future<Quotation> create({required String customerId, required List<QuotedLine> lines, required bool submit, String? note}) async {
    sentCustomer = customerId;
    sentLines = lines;
    sentSubmit = submit;
    return current;
  }

  @override
  Future<Quotation> act(String id, String action, {String? note}) async {
    calls.add(note == null ? action : '$action:$note');
    current = switch (action) {
      'send' => _q('sent', {'answer': true}),
      'accept' => _q('accepted', {'convert': true}),
      _ => _q('converted', {}),
    };
    return current;
  }
}

void main() {
  testWidgets('each step shows only its own button, and a refusal needs its reason', (tester) async {
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: QuotationScreen(id: 'q1', api: api)));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('quotation-do-send')), findsOneWidget);
    expect(find.byKey(const Key('quotation-do-accept')), findsNothing);
    expect(find.byKey(const Key('quotation-do-convert')), findsNothing);

    await tester.tap(find.byKey(const Key('quotation-do-send')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('quotation-do-send')), findsNothing);

    await tester.tap(find.byKey(const Key('quotation-do-reject')));
    await tester.pumpAndSettle();
    expect(api.calls, ['send'], reason: 'কারণ ছাড়া "রাজি নন" সার্ভারে যায় না');
    expect(find.text('রাজি না হওয়ার কারণ লিখুন।'), findsOneWidget);

    await tester.tap(find.byKey(const Key('quotation-do-accept')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('quotation-do-convert')));
    await tester.pumpAndSettle();
    expect(api.calls, ['send', 'accept', 'convert']);
  });

  testWidgets('a new quotation sends the shop, the lines and the rate typed in', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(
      home: NewQuotationScreen(
        api: api,
        customers: const [CustomerRecord({'id': 'c1', 'nameBn': 'রহিম স্টোর'})],
        products: const [ProductRecord({'id': 'p1', 'nameBn': 'কসমস বিস্কুট', 'salePrice': '40.00'})],
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('quotation-submit')));
    await tester.pumpAndSettle();
    expect(api.sentSubmit, isNull);
    expect(find.text('দোকান বাছুন।'), findsOneWidget);

    await tester.tap(find.byKey(const Key('quotation-customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('রহিম স্টোর'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('quotation-add-line')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('কসমস বিস্কুট'));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(TextField, '40.00'), findsOneWidget, reason: 'দর আগে থেকে পণ্যের দাম');
    await tester.enterText(find.byKey(const Key('quotation-qty')), '12');
    await tester.enterText(find.byKey(const Key('quotation-rate')), '38.50');
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('quotation-submit')));
    await tester.pumpAndSettle();

    expect(api.sentCustomer, 'c1');
    expect(api.sentSubmit, isTrue);
    expect(api.sentLines!.single.productId, 'p1');
    expect(api.sentLines!.single.qty, 12);
    expect(api.sentLines!.single.rate, 38.5);
  });
}
