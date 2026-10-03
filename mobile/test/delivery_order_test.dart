import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ডেলিভারি অর্ডার (0.4.8) — মালিকের বিক্রয়-ধারা §২ক-খ। নিয়ম সার্ভারের; ফোন যা পাঠায় আর যা দেখায় তা-ই দাবি।
class _FakeApi implements DeliveryOrderApi {
  bool? askedAwaiting;
  String? sentCustomer;
  List<WantedLine>? sentLines;
  bool? sentSubmit;
  Map<int, int>? sentQty;
  final List<String> calls = [];

  DeliveryOrder current = _order(awaitingMe: false, editable: true, status: 'খসড়া');

  static DeliveryOrder _order({required bool awaitingMe, required bool editable, required String status, double qty = 10}) =>
      DeliveryOrder(
        id: 'do-1', no: 'DO-0001', date: '2026-10-03', customer: 'রহিম স্টোর', status: 'x', statusLabel: status,
        total: qty * 40, editable: editable, awaitingMe: awaitingMe, approvalId: awaitingMe ? 'ap-1' : null,
        lines: [DeliveryOrderLine(id: 7, product: 'কসমস বিস্কুট', qty: 10, approvedQty: null, finalQty: qty, lineTotal: qty * 40)],
      );

  @override
  Future<List<DeliveryOrder>> list({bool awaitingMe = false}) async {
    askedAwaiting = awaitingMe;
    return [current];
  }

  @override
  Future<DeliveryOrder> show(String id) async => current;

  @override
  Future<DeliveryOrder> create({required String customerId, required List<WantedLine> lines, required bool submit, String? note}) async {
    sentCustomer = customerId;
    sentLines = lines;
    sentSubmit = submit;
    return current;
  }

  @override
  Future<DeliveryOrder> submit(String id) async {
    calls.add('submit');
    return current = _order(awaitingMe: false, editable: false, status: 'সুপারভাইজারের অপেক্ষায়');
  }

  @override
  Future<DeliveryOrder> setQuantities(String id, Map<int, int> qtyByLine) async {
    calls.add('qty');
    sentQty = qtyByLine;
    return current = _order(awaitingMe: true, editable: false, status: 'সুপারভাইজারের অপেক্ষায়', qty: qtyByLine[7]!.toDouble());
  }

  @override
  Future<void> approve(String approvalId) async {
    calls.add('approve:$approvalId');
    current = _order(awaitingMe: false, editable: false, status: 'অনুমোদিত', qty: current.lines.first.finalQty);
  }

  @override
  Future<void> reject(String approvalId, String reason) async => calls.add('reject:$reason');
}

void main() {
  testWidgets('the list asks for "awaiting me" only when that chip is picked, and a writer gets the new-DO button', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: DeliveryOrderListScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.text('DO-0001'), findsOneWidget);
    expect(api.askedAwaiting, isFalse);
    expect(find.byKey(const ValueKey('do-new')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('do-awaiting-me')));
    await tester.pumpAndSettle();
    expect(api.askedAwaiting, isTrue);

    await tester.pumpWidget(MaterialApp(home: DeliveryOrderListScreen(api: _FakeApi(), canWrite: false)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('do-new')), findsNothing, reason: 'লেখার চাবি নেই, তবু "নতুন DO"');
  });

  testWidgets('a new DO sends the dealer and one line per product with no price, and submits when asked', (tester) async {
    final api = _FakeApi();
    const dealer = CustomerRecord({'id': 'cus-uuid', 'nameBn': 'রহিম স্টোর'});
    const biscuit = ProductRecord({'id': 'prd-uuid', 'nameBn': 'কসমস বিস্কুট', 'salePrice': '40'});

    await tester.pumpWidget(MaterialApp(
        home: NewDeliveryOrderScreen(api: api, customers: const [dealer], products: const [biscuit])));

    // ডিলার ছাড়া জমা — ফোনেই থামে, সার্ভারে যায় না
    await tester.tap(find.byKey(const ValueKey('do-submit')));
    await tester.pumpAndSettle();
    expect(find.text('ডিলার বাছুন।'), findsOneWidget);
    expect(api.sentCustomer, isNull);

    await tester.tap(find.byKey(const ValueKey('do-customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('রহিম স্টোর').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('do-add-line')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('কসমস বিস্কুট').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('do-qty')), '12');
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const ValueKey('do-submit')));
    await tester.tap(find.byKey(const ValueKey('do-submit')));
    await tester.pumpAndSettle();

    expect(api.sentCustomer, 'cus-uuid');
    expect(api.sentLines!.single.productId, 'prd-uuid');
    expect(api.sentLines!.single.qty, 12);
    expect(api.sentSubmit, isTrue);
  });

  testWidgets('the writer submits a draft; the supervisor lowers the quantity and signs through the approval door', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: DeliveryOrderScreen(id: 'do-1', api: api)));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('do-approve')), findsNothing, reason: 'লেখকের পাতায় সইয়ের বোতাম');
    await tester.tap(find.byKey(const ValueKey('do-send')));
    await tester.pumpAndSettle();
    expect(api.calls, ['submit']);
    expect(find.text('অবস্থা: সুপারভাইজারের অপেক্ষায়'), findsOneWidget);

    // এখন সুপারভাইজারের চোখে
    api.current = _FakeApi._order(awaitingMe: true, editable: false, status: 'সুপারভাইজারের অপেক্ষায়');
    await tester.pumpWidget(MaterialApp(home: DeliveryOrderScreen(key: const ValueKey('sup'), id: 'do-1', api: api)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('do-send')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('do-line-7')), '6');
    await tester.ensureVisible(find.byKey(const ValueKey('do-approve')));
    await tester.tap(find.byKey(const ValueKey('do-approve')));
    await tester.pumpAndSettle();

    expect(api.sentQty, {7: 6}, reason: 'যা ঘরে দেখছেন তাতেই সই — আগে পরিমাণ যায়');
    expect(api.calls, ['submit', 'qty', 'approve:ap-1']);
    expect(find.text('অবস্থা: অনুমোদিত'), findsOneWidget);
  });

  testWidgets('sending back needs a reason before anything reaches the server', (tester) async {
    // ⓘ লম্বা ফোনের মাপ — ফেরতের বোতাম পর্দার কিনারায় পড়ে ট্যাপ ফসকাত
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = _FakeApi()..current = _FakeApi._order(awaitingMe: true, editable: false, status: 'সুপারভাইজারের অপেক্ষায়');
    await tester.pumpWidget(MaterialApp(home: DeliveryOrderScreen(id: 'do-1', api: api)));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const ValueKey('do-reject')));
    await tester.tap(find.byKey(const ValueKey('do-reject')));
    await tester.pumpAndSettle();
    expect(api.calls, isEmpty);
    expect(find.text('ফেরতের কারণ লিখুন।'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('do-reason')), 'মজুদ নেই');
    await tester.ensureVisible(find.byKey(const ValueKey('do-reject')));
    await tester.tap(find.byKey(const ValueKey('do-reject')));
    await tester.pumpAndSettle();
    expect(api.calls, ['reject:মজুদ নেই']);
  });
}
