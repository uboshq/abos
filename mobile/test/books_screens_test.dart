import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/books/principal_screens.dart';
import 'package:abos_mobile/features/books/purchase_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ফোনে টাকা আদায়, প্রিন্সিপাল আর ক্রয় (0.4.17, মালিক ৬ অক্টোবর ২০২৬) — কেবল পড়া; দেয়াল সার্ভারের, ফোন সত্যি করে দেখায়।
class _FakeBooks implements BooksApi {
  final List<Map<String, Object?>> asked = [];

  @override
  Future<MoneyInPage> moneyIn(
      {required String from,
      required String to,
      String? q,
      String? method,
      int page = 1}) async {
    asked.add({'from': from, 'to': to, 'q': q, 'method': method, 'page': page});
    return const MoneyInPage(
      rows: [
        MoneyInRow(
            id: 'c1',
            no: 'RCV-0002',
            date: '2026-10-06',
            customer: 'রহিম স্টোর',
            account: 'প্রধান নগদ',
            method: 'cash',
            amount: 1500),
        MoneyInRow(
            id: 'c2',
            no: 'RCV-0001',
            date: '2026-10-05',
            customer: 'করিম ট্রেডার্স',
            account: 'DBBL',
            method: 'cheque',
            amount: 2500),
      ],
      total: 4000,
      count: 2,
    );
  }

  @override
  Future<MoneyInDetail> moneyInDetail(String id) async => const MoneyInDetail(
        row: MoneyInRow(
            id: 'c2',
            no: 'RCV-0001',
            date: '2026-10-05',
            customer: 'করিম ট্রেডার্স',
            account: 'DBBL',
            method: 'cheque',
            amount: 2500),
        instrumentNo: 'CHQ-7781',
        lines: [('S-0041', 2000), ('S-0042', 500)],
      );

  @override
  Future<(List<PrincipalRow>, int?)> principals(
      {String? q, int page = 1}) async {
    asked.add({'principals': q});
    return (
      const [
        PrincipalRow(
            id: 'p1',
            name: 'Star Line',
            balance: 1156172.16,
            lastPurchaseOn: '2026-10-01'),
        PrincipalRow(id: 'p2', name: 'Akij', balance: -2500),
      ],
      null
    );
  }

  @override
  Future<PrincipalLedger> principal(String id, {int page = 1}) async =>
      const PrincipalLedger(
        principal: PrincipalRow(
            id: 'p1',
            name: 'Star Line',
            fullName: 'Star Line Food Products',
            balance: 1156172.16),
        entries: [
          LedgerLine(
              date: '2026-10-01',
              no: 'PB-0009',
              narration: 'ক্রয় বিল',
              debit: 0,
              credit: 50000,
              balance: 1156172.16),
        ],
      );

  bool withCost = false;

  /// ⛔ দামের চাবি ছাড়া সার্ভার কোনো অঙ্ক পাঠায় না (অডিট ক্রয় ⚠️১৫)
  bool withMoney = true;

  @override
  Future<PurchasePage> purchases(
      {required String kind,
      required String from,
      required String to,
      String? principal,
      int page = 1}) async {
    asked.add({'kind': kind, 'from': from, 'to': to, 'principal': principal});
    return PurchasePage(
      rows: [
        PurchaseRow(
            kind: kind,
            id: 'b1',
            no: kind == 'bill' ? 'PB-0009' : 'GRN-0003',
            date: '2026-10-01',
            principal: 'Star Line',
            statusLabel: 'নিশ্চিত',
            total: withMoney ? 50000 : null,
            paid: kind == 'bill' && withMoney ? 20000 : null,
            due: kind == 'bill' && withMoney ? 30000 : null),
      ],
      total: withMoney ? 50000 : null,
      count: 1,
    );
  }

  @override
  Future<PurchaseDetail> purchase(String kind, String id) async =>
      PurchaseDetail(
        row: PurchaseRow(
            kind: 'bill',
            id: 'b1',
            no: 'PB-0009',
            principal: 'Star Line',
            statusLabel: 'নিশ্চিত',
            total: withMoney ? 50000 : null,
            paid: withMoney ? 20000 : null,
            due: withMoney ? 30000 : null),
        lines: [
          PurchaseLine(
              product: 'কসমস বিস্কুট',
              qty: 100,
              rate: withCost ? 500 : null,
              amount: withCost ? 50000 : null),
        ],
      );
}

Future<void> _pump(WidgetTester tester, Widget screen) async {
  // ⓘ নতুন গাছ — একই গাছে আবার পাম্প করলে আগের পর্দার অবস্থা থেকে যেত
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

final _today = DateTime(2026, 10, 6);

void main() {
  testWidgets(
      'money in: this month by default, the total of the filter, method words, and a method chip asks the server',
      (tester) async {
    final api = _FakeBooks();
    await _pump(tester, MoneyInListScreen(api: api, today: _today));

    expect(api.asked.first, containsPair('from', '2026-10-01'));
    expect(api.asked.first, containsPair('to', '2026-10-06'));
    expect(find.text('৳4,000'), findsOneWidget,
        reason: 'মোট সার্ভারের গোটা ছাঁকনির');
    expect(find.text('· 2 টি'), findsOneWidget);
    expect(find.textContaining('RCV-0001 · 05/10/2026 · চেক'), findsOneWidget);
    expect(find.textContaining('RCV-0002 · 06/10/2026 · নগদ'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('method-cash')));
    await tester.pumpAndSettle();
    expect(api.asked.last, containsPair('method', 'cash'));

    await tester.tap(find.byKey(const ValueKey('range-today')));
    await tester.pumpAndSettle();
    expect(api.asked.last, containsPair('from', '2026-10-06'));

    await tester.enterText(
        find.byKey(const ValueKey('money-in-search')), 'রহিম');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.asked.last, containsPair('q', 'রহিম'));
  });

  testWidgets('money in detail: which bill took how much', (tester) async {
    await _pump(tester, MoneyInListScreen(api: _FakeBooks(), today: _today));
    await tester.tap(find.byKey(const ValueKey('money-in-RCV-0001')));
    await tester.pumpAndSettle();

    expect(find.text('CHQ-7781'), findsOneWidget);
    expect(find.text('S-0041'), findsOneWidget);
    expect(find.text('৳2,000'), findsOneWidget);
  });

  testWidgets(
      'principals: the balance in words, red to pay, green to get; a tap opens the ledger',
      (tester) async {
    await _pump(tester, PrincipalListScreen(api: _FakeBooks()));

    expect(find.text('দিতে হবে: ৳1,156,172.16'), findsOneWidget);
    expect(find.text('পাব: ৳2,500'), findsOneWidget,
        reason: '⛔ ঋণাত্মক জের খালি বিয়োগে');
    expect(find.text('শেষ ক্রয়: 01/10/2026'), findsOneWidget);
    expect(find.text('এখনো কোনো ক্রয় নেই'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('principal-p1')));
    await tester.pumpAndSettle();
    expect(find.text('Star Line Food Products'), findsOneWidget);
    expect(find.text('01/10/2026 · PB-0009'), findsOneWidget);
    expect(find.text('+৳50,000'), findsOneWidget);
  });

  testWidgets(
      'purchases: paid and due on a bill, the principal filter goes to the server, receipts only with their key',
      (tester) async {
    final api = _FakeBooks();
    await _pump(tester, PurchaseListScreen(api: api, today: _today));

    expect(
        find.textContaining('পরিশোধিত ৳20,000 · বাকি ৳30,000'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('purchase-principal')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Star Line').last);
    await tester.pumpAndSettle();
    expect(api.asked.last, containsPair('principal', 'p1'));

    await tester.tap(find.byKey(const ValueKey('purchase-kind-receipt')));
    await tester.pumpAndSettle();
    expect(api.asked.last, containsPair('kind', 'receipt'));
    expect(find.textContaining('পরিশোধিত'), findsNothing,
        reason: 'মাল গ্রহণে পরিশোধ নেই');

    await _pump(
        tester,
        PurchaseListScreen(
            api: _FakeBooks(), today: _today, canSeeReceipts: false));
    expect(find.byKey(const ValueKey('purchase-kind-receipt')), findsNothing,
        reason: '⛔ চাবি ছাড়া মাল গ্রহণের বোতাম');
  });

  testWidgets('purchase detail: the cost rate only when the server sends it',
      (tester) async {
    final api = _FakeBooks();
    await _pump(tester, PurchaseScreen(kind: 'bill', id: 'b1', api: api));
    expect(find.textContaining('দর'), findsNothing,
        reason: '⛔ খরচের চাবি ছাড়া কেনা দর');

    api.withCost = true;
    await _pump(tester, PurchaseScreen(kind: 'bill', id: 'b1', api: api));
    expect(find.textContaining('দর ৳500'), findsOneWidget);
  });

  test('a purchase row the server sent without its money reads as no money, not ৳0', () {
    final row = PurchaseRow.fromJson(const {'kind': 'bill', 'id': 'b1', 'no': 'PB-0009', 'principal': 'Star Line'});
    expect(row.total, isNull);
    expect(row.paid, isNull);
    expect(row.due, isNull);
    expect(PurchaseRow.fromJson(const {'total': '50000.0000'}).total, 50000);
  });

  testWidgets('without the cost key: no total anywhere, and never a zero taka',
      (tester) async {
    final api = _FakeBooks()..withMoney = false;
    await _pump(tester, PurchaseListScreen(api: api, today: _today));
    expect(find.textContaining('৳'), findsNothing,
        reason: '⛔ চাবি ছাড়া অঙ্ক দেখাল — নাকি ৳০?');
    expect(find.text('—'), findsOneWidget);

    await _pump(tester, PurchaseScreen(kind: 'bill', id: 'b1', api: api));
    expect(find.text('মোট'), findsNothing);
    expect(find.textContaining('৳'), findsNothing);

    // ⓘ চাবিসহ একই পর্দায় অঙ্ক আসে — দাবিটা অন্ধ নয়
    api.withMoney = true;
    await _pump(tester, PurchaseScreen(kind: 'bill', id: 'b1', api: api));
    expect(find.text('মোট'), findsOneWidget);
  });

  group('the three tiles follow the keys', () {
    const repository = MenuRepository();
    Set<String> keysOf(List<String> permissions) => repository
        .ordered(
            const [],
            AuthUser(
                id: '1',
                name: 'X',
                email: 'x@abos.test',
                roles: const [],
                permissions: permissions))
        .map((i) => i.key)
        .toSet();

    test('each key brings its own tile and no other', () {
      expect(keysOf(['sales.collection.view']),
          containsAll(['sales.collections']));
      expect(keysOf(['sales.collection.view']),
          isNot(contains('purchase.purchases')));
      expect(keysOf(['supplier.view']), contains('purchase.principals'));
      expect(keysOf(['purchase.bill.view']), contains('purchase.purchases'));
      expect(
          keysOf(['customer.view']).intersection({
            'sales.collections',
            'purchase.principals',
            'purchase.purchases'
          }),
          isEmpty);
    });

    test(
        'the purchase switch closes principals and purchases; the sales switch closes money in',
        () {
      expect(ModuleGate.moduleOfPath['principals'], 'purchase');
      expect(ModuleGate.moduleOfPath['purchases'], 'purchase');
      expect(ModuleGate.moduleOfPath['collections'], 'sales');
    });
  });
}
