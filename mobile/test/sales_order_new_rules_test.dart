import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/orders/tracking_api.dart';
import 'package:abos_mobile/features/deliveries/deliveries_screen.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ The sales order under the new rules (0.4.20, coordinator 6 Oct 2026): stock is held from the challan, so the
/// order shows "stock now — not held" (have, want, short; numbers only with the stock key); the list carries the web's
/// chips (back order, credit hold); and the new paper numbers (CHA-0154) show beside the sale number (S-0154).
class _FakeApi implements DeliveryOrderApi {
  _FakeApi(this.current);

  DeliveryOrder current;

  @override
  bool get forOrders => true;

  @override
  Future<List<DeliveryOrder>> list({bool awaitingMe = false}) async =>
      [current];

  @override
  Future<DeliveryOrder> show(String id) async => current;

  @override
  Future<DeliveryOrder> create(
          {required String customerId,
          required List<WantedLine> lines,
          required bool submit,
          String? note}) async =>
      current;

  @override
  Future<DeliveryOrder> submit(String id) async => current;

  @override
  Future<DeliveryOrder> setQuantities(
          String id, Map<int, int> qtyByLine) async =>
      current;

  @override
  Future<void> approve(String approvalId) async {}

  @override
  Future<void> reject(String approvalId, String reason) async {}
}

DeliveryOrder _order(List<Map<String, dynamic>> atp) => DeliveryOrder.fromJson({
      'kind': 'so',
      'id': 'so-1',
      'no': 'SO-0001',
      'customer': {'name': 'রহিম স্টোর'},
      'status': 'confirmed',
      'status_label': 'নিশ্চিত',
      'total': '400',
      'back_order': true,
      'chips': [
        {'key': 'status', 'label': 'নিশ্চিত', 'tone': 'success'},
        {'key': 'delivery', 'label': 'আংশিক চালান', 'tone': 'warning'},
        {'key': 'back_order', 'label': 'ব্যাক অর্ডার', 'tone': 'danger'},
      ],
      'atp': atp,
      'lines': const [],
    });

Future<void> _pump(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('the list carries the web\'s chips — back order among them',
      (tester) async {
    await _pump(
        tester, DeliveryOrderListScreen(api: _FakeApi(_order(const []))));
    expect(find.byKey(const ValueKey('order-chip-back_order')), findsOneWidget,
        reason: '⛔ the list does not say the order is a back order');
    expect(find.text('আংশিক চালান'), findsOneWidget);
  });

  testWidgets('a credit-held order says so as a chip', (tester) async {
    final held = DeliveryOrder.fromJson({
      'id': 'so-2',
      'no': 'SO-0002',
      'status': 'credit_held',
      'status_label': 'সীমায় আটকে',
      'total': '100',
      'credit_held': true,
      'credit_short': '500.00',
      'chips': [
        {'key': 'status', 'label': 'সীমায় আটকে', 'tone': 'danger'}
      ],
    });
    await _pump(tester, DeliveryOrderListScreen(api: _FakeApi(held)));
    expect(find.byKey(const ValueKey('order-chip-status')), findsOneWidget);
    expect(find.text('সীমায় আটকে'), findsOneWidget);
  });

  testWidgets('with the stock key: have, want, short', (tester) async {
    await _pump(
        tester,
        DeliveryOrderScreen(
            id: 'so-1',
            api: _FakeApi(_order(const [
              {
                'product': 'কসমস বিস্কুট',
                'want': '15',
                'enough': false,
                'have': '10',
                'short': '5'
              }
            ]))));
    expect(find.byKey(const ValueKey('order-stock-now')), findsOneWidget,
        reason: '⛔ the order page does not say what is there now');
    expect(find.text('আছে 10 · চাই 15'), findsOneWidget);
    expect(find.text('কম 5'), findsOneWidget);
  });

  testWidgets('without the stock key: no number of stock, only "not enough"',
      (tester) async {
    await _pump(
        tester,
        DeliveryOrderScreen(
            id: 'so-1',
            api: _FakeApi(_order(const [
              {'product': 'কসমস বিস্কুট', 'want': '15', 'enough': false}
            ]))));
    expect(find.text('চাই 15'), findsOneWidget);
    expect(find.text('মজুদে কুলোয় না'), findsOneWidget);
    expect(find.textContaining(RegExp(r'আছে \d')), findsNothing,
        reason: '⛔ a stock figure on an SR\'s phone (owner, 1 Oct 2026)');
  });

  test('the new numbers: the paper\'s own and the sale\'s, both read', () {
    final sale = TrackedSale.fromJson({
      'kind': 'challan',
      'id': 'c1',
      'no': 'S-0154',
      'document_no': 'CHA-0154',
      'total': '10',
      'step': 'x'
    });
    expect([sale.no, sale.documentNo], ['S-0154', 'CHA-0154']);

    final run = DeliveryRunRow.fromJson({
      'token': 't',
      'document_no': 'CHA-0154',
      'sale_no': 'S-0154',
      'customer': 'রহিম'
    });
    expect([run.documentNo, run.saleNo], ['CHA-0154', 'S-0154']);
  });

  testWidgets('today\'s deliveries show CHA- beside S-', (tester) async {
    await _pump(
        tester,
        const DeliveriesScreen(
            api: _OneRun(DeliveryRunRow(
                token: 't',
                documentNo: 'CHA-0154',
                saleNo: 'S-0154',
                customer: 'রহিম'))));
    expect(find.text('CHA-0154 · S-0154'), findsOneWidget);
  });
}

class _OneRun implements DeliveryRunApi {
  const _OneRun(this.row);

  final DeliveryRunRow row;

  @override
  Future<DeliveryRunPage> today({int page = 1}) async => DeliveryRunPage([row]);
}
