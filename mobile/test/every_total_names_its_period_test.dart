import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:abos_mobile/features/papers/paper_screens.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

/// ⭐ প্রতিটা মোটের পাশে তার সময়কাল — পর্দার চালু তারিখের ছাঁকনি থেকে (fe-র মোটের নিরীক্ষা, ২৪ অক্টোবর ২০২৬; ০.৪.৪৮)।
///
/// <p>দাবি: আদায়, ভাউচার আর কাগজের পর্দায় মোটের পাশের লেখা চালু ছাঁকনির সাথে মেলে ("এ মাস"), ছাঁকনি বদলালে বদলায় ("আজ");
/// তারিখহীন কাগজ আর ডিওর ডেস্ক বলে "সব তারিখ"; নিজে বাছা পরিসর "dd/MM/yyyy – dd/MM/yyyy"।
void main() {
  final today = DateTime(2026, 10, 24);

  Future<void> pump(WidgetTester tester, Widget home) async {
    tester.view.physicalSize = const Size(400, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: home));
    await tester.pumpAndSettle();
  }

  String period(WidgetTester tester) {
    final label = find.byKey(const ValueKey('totals-period'));
    expect(label, findsOneWidget, reason: '⛔ মোটের পাশে সময়কাল নেই');
    return tester.widget<Text>(find.descendant(of: label, matching: find.byType(Text))).data!;
  }

  Future<void> pickToday(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey('range-today')));
    await tester.pumpAndSettle();
  }

  testWidgets('collections: the totals bar says "এ মাস", and "আজ" once today is picked', (tester) async {
    final api = _Collections();
    await pump(tester, MoneyInListScreen(api: api, today: today, dues: () => const [], unsent: () => const []));
    expect(period(tester), 'এ মাস', reason: '⛔ আদায়ের মোটের সময়কাল চালু ছাঁকনির নয়');
    await pickToday(tester);
    expect(api.asked.last, ('2026-10-24', '2026-10-24'));
    expect(period(tester), 'আজ', reason: '⛔ ছাঁকনি বদলাল, আদায়ের মোটের সময়কাল বদলাল না');
  });

  testWidgets('vouchers: the total strip says "এ মাস", and "আজ" once today is picked', (tester) async {
    await pump(tester, VoucherListScreen(api: _Vouchers(), newChangeId: () => 'k', branches: () async => const [], today: today));
    expect(period(tester), 'এ মাস', reason: '⛔ ভাউচারের মোটের সময়কাল চালু ছাঁকনির নয়');
    await pickToday(tester);
    expect(period(tester), 'আজ', reason: '⛔ ছাঁকনি বদলাল, ভাউচারের মোটের সময়কাল বদলাল না');
  });

  testWidgets('papers: dated papers follow the filter; undated papers say "সব তারিখ"', (tester) async {
    await pump(tester, PaperListScreen(paperKey: 'x', api: _Papers(dated: true), branches: () async => const [], today: today));
    expect(period(tester), 'এ মাস', reason: '⛔ কাগজের মোটের সময়কাল চালু ছাঁকনির নয়');
    await pickToday(tester);
    expect(period(tester), 'আজ', reason: '⛔ ছাঁকনি বদলাল, কাগজের মোটের সময়কাল বদলাল না');

    await tester.pumpWidget(const SizedBox());
    await pump(tester, PaperListScreen(paperKey: 'y', api: _Papers(dated: false), branches: () async => const [], today: today));
    expect(period(tester), 'সব তারিখ', reason: '⛔ তারিখহীন কাগজের মোট "সব তারিখ" বলে না');
  });

  testWidgets('the delivery-order desk has no date filter: "সব তারিখ"', (tester) async {
    await pump(tester, DeliveryOrderListScreen(api: _Orders(), canWrite: false));
    expect(period(tester), 'সব তারিখ');
  });

  testWidgets('a chosen range reads as its two days; one day as the day', (tester) async {
    late BuildContext ctx;
    await tester.pumpWidget(MaterialApp(home: Builder(builder: (c) {
      ctx = c;
      return const SizedBox();
    })));
    expect(TotalsPeriod.range(DateTime(2026, 10, 1), DateTime(2026, 10, 15), today: today).label(ctx), '01/10/2026 – 15/10/2026');
    expect(TotalsPeriod.range(DateTime(2026, 9, 3), DateTime(2026, 9, 3), today: today).label(ctx), '03/09/2026');
    expect(TotalsPeriod.range(DateTime(2026, 10, 1), today, today: today).label(ctx), 'এ মাস');
    expect(const TotalsPeriod.allDates().label(ctx), 'সব তারিখ');
  });
}

class _Collections implements BooksApi {
  final asked = <(String, String)>[];

  @override
  Future<MoneyInPage> moneyIn({required String from, required String to, String? q, String? method, int page = 1}) async {
    asked.add((from, to));
    return const MoneyInPage(rows: [
      MoneyInRow(id: 'a', no: 'RV-1', date: '2026-10-20', customer: 'রহিম স্টোর', account: 'নগদ', method: 'cash', amount: 500),
    ], total: 500, count: 1);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Vouchers implements VoucherApi {
  @override
  Future<VoucherListPage> list(
          {String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: [], count: 0, total: 0);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Papers implements PaperApi {
  _Papers({required this.dated});

  final bool dated;

  @override
  Future<PaperPage> list(String key,
          {String? from, String? to, String q = '', String? status, String? branch, int page = 1}) async =>
      PaperPage(
        rows: const [PaperRow(id: 'p', no: 'SR-1', total: '100.00')],
        filters: PaperFilters(dated: dated),
        count: 1,
        sum: '100.00',
        pageSum: '100.00',
      );

  @override
  Future<PaperDetail> one(String key, String id) => throw UnimplementedError();
}

class _Orders implements DeliveryOrderApi {
  @override
  bool get forOrders => false;

  @override
  Future<(List<DeliveryOrder>, PaperTotal?, int?)> list({bool awaitingMe = false, int page = 1}) async => (
        const [
          DeliveryOrder(id: 'd', no: 'DO-1', date: null, customer: 'রহিম স্টোর', status: 'draft', statusLabel: 'খসড়া', total: 10,
              editable: false, awaitingMe: false),
        ],
        const PaperTotal(sum: 10, count: 1),
        null,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
