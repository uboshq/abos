import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/shown_rows.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:abos_mobile/features/papers/paper_screens.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ "এই পাতা" অ্যাপজুড়ে একটাই মানে — পর্দায় এখন যে সারিগুলো দেখা যাচ্ছে ([ShownRows]; fe-র মোটের নিরীক্ষা, ২৪ অক্টোবর
/// ২০২৬; ০.৪.৪৮)।
///
/// <p>দাবি:
/// - কাগজের পর্দা: দুই খেপ (৩০ + ১২) আনার পর "এই পাতা" ৪২টা সারির পুরো যোগ। ⛔ আগে সার্ভারের `page_sum` — কেবল শেষ ১২টা।
/// - ডিওর ডেস্ক: "আরও দেখুন" পাতা ২ চায়, তার সারি তালিকায় আসে, "এই পাতা" দুই পাতার দেখানো সারির যোগ। ⛔ আগে কেবল পাতা ১।
/// - আদায়, বকেয়া: "এই পাতা" = দেখানো সারির যোগ (আদায়ে সব খেপ, বকেয়ায় খোঁজার পরে যা থাকে)।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  setUp(() async {
    await ReferenceCache.instance.clearAll();
    ReferenceSync.forgetLastFailure();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  Future<void> pump(WidgetTester tester, Widget home, {double height = 1600}) async {
    tester.view.physicalSize = Size(400, height);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: home));
    await tester.pumpAndSettle();
  }

  String textsIn(WidgetTester tester, Finder of) =>
      tester.widgetList<Text>(find.descendant(of: of, matching: find.byType(Text))).map((t) => t.data).join(' | ');

  Future<void> tapMore(WidgetTester tester, Key key) async {
    await tester.scrollUntilVisible(find.byKey(key), 300, scrollable: find.byType(Scrollable).first);
    await tester.tap(find.byKey(key));
    await tester.pumpAndSettle();
  }

  test('ShownRows counts every shown row and adds the counted ones in paisa', () {
    final rows = [('a', '0.10', true), ('b', 0.2, true), ('c', '500', false), ('d', null, true)];
    final shown = ShownRows.of(rows, (r) => r.$2, counts: (r) => r.$3);
    expect(shown.count, 4, reason: 'যোগে না-পড়া সারিও দেখা যাচ্ছে — গোনায় থাকে');
    expect(shown.sum, 0.3, reason: 'পয়সায় যোগ, ভাসমান ভুল নেই; যোগে না-পড়া আর টাকাহীন সারি বাদ');
  });

  testWidgets('papers: after two batches (30 + 12), "this page" is the sum of all 42 rows, not the last batch', (tester) async {
    final api = _Papers();
    await pump(tester, PaperListScreen(paperKey: 'sales-returns', api: api, branches: () async => const [], today: DateTime(2026, 10, 24)),
        height: 4000);
    expect(textsIn(tester, find.byKey(const ValueKey('paper-page-sum'))), contains('৳3,000.00'));

    await tapMore(tester, const ValueKey('paper-more'));
    expect(api.pages, [1, 2]);
    final line = textsIn(tester, find.byKey(const ValueKey('paper-page-sum')));
    expect(line, contains('৳3,600.00'), reason: '⛔ "এই পাতা" দেখানো ৪২টা সারির যোগ নয় — শেষ খেপটা একা যোগ হয়েছে');
    expect(line, contains('42'), reason: '⛔ "এই পাতা"-র গোনা দেখানো সারির নয়');
    await tester.scrollUntilVisible(find.byKey(const ValueKey('paper-r2-11')), 300, scrollable: find.byType(Scrollable).first);
    expect(find.byKey(const ValueKey('paper-r2-11')), findsOneWidget, reason: 'দ্বিতীয় খেপের শেষ সারি তালিকায়');
  });

  testWidgets('delivery orders: "more" asks for page 2, its rows appear, and "this page" adds every shown row', (tester) async {
    final api = _Orders();
    await pump(tester, DeliveryOrderListScreen(api: api, canWrite: false));
    expect(api.pages, [1]);
    expect(textsIn(tester, find.byKey(const ValueKey('totals-page'))), contains('৳1,500.00'));

    await tapMore(tester, const ValueKey('books-more'));
    expect(api.pages, [1, 2], reason: '⛔ ডেস্ক পাতা ২ চায়নি');
    expect(find.byKey(const ValueKey('do-row-p2')), findsOneWidget, reason: '⛔ পাতা ২-এর সারি তালিকায় আসেনি');
    expect(find.byKey(const ValueKey('do-row-p1a')), findsOneWidget, reason: 'পাতা ১-এর সারি থাকে');
    final page = textsIn(tester, find.byKey(const ValueKey('totals-page')));
    expect(page, contains('৳1,750.00'), reason: '⛔ "এই পাতা" দেখানো সারির যোগ নয় (প্রত্যাখ্যাত বাদ, দুই পাতা মিলে)');
    expect(page, contains('3টি'));
    expect(find.byKey(const ValueKey('books-more')), findsNothing, reason: 'শেষ পাতার পরে "আরও" নেই');
  });

  testWidgets('collections: "this page" adds every batch shown so far', (tester) async {
    final api = _Collections();
    await pump(tester,
        MoneyInListScreen(api: api, today: DateTime(2026, 10, 24), dues: () => const [], unsent: () => const []));
    await tapMore(tester, const ValueKey('books-more'));
    expect(api.pages, [1, 2]);
    final page = textsIn(tester, find.byKey(const ValueKey('totals-page')));
    expect(page, contains('৳1,750.00'), reason: '⛔ আদায়ের "এই পাতা" দেখানো সব সারির যোগ নয়');
    expect(page, contains('3টি'));
  });

  testWidgets('dues: "this page" is what the search leaves on screen, the grand total the whole list', (tester) async {
    final now = DateTime(2026, 10, 1);
    await tester.runAsync(() async {
      for (final (id, name, due) in [('c-a', 'আলিফ স্টোর', '10000.0000'), ('c-b', 'বকুল স্টোর', '5000.0000')]) {
        await ReferenceCache.instance.put(entityType: 'Customer', entityId: id, updatedAt: now,
            payload: {'id': id, 'nameBn': name, 'pointName': 'ফুলপুর', 'branchName': 'ময়মনসিংহ'});
        await ReferenceCache.instance.put(entityType: 'CustomerDue', entityId: id, updatedAt: now,
            payload: {'customerId': id, 'outstanding': due, 'outstandingInView': due, 'creditLimit': '0', 'creditDays': 0});
      }
    });
    await pump(tester, DueListScreen(refresh: () async {}));
    await tester.enterText(find.byType(TextField), 'আলিফ');
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
    final page = textsIn(tester, find.byKey(const ValueKey('totals-page')));
    expect(page, contains('৳10,000.00'), reason: '⛔ বকেয়ার "এই পাতা" খোঁজার পরে দেখানো সারির যোগ নয়');
    expect(page, contains('1টি'));
    expect(textsIn(tester, find.byKey(const ValueKey('totals-all'))), contains('৳15,000.00'));
  });
}

/// নকল কাগজের দরজা — পাতা ১-এ ৩০টা সারি (প্রতিটা ৳100), পাতা ২-এ ১২টা (প্রতিটা ৳50); সার্ভারের মতো `page_sum` কেবল সেই খেপের
class _Papers implements PaperApi {
  final pages = <int>[];

  @override
  Future<PaperPage> list(String key,
      {String? from, String? to, String q = '', String? status, String? branch, int page = 1}) async {
    pages.add(page);
    final first = page == 1;
    final rows = [
      for (var i = 0; i < (first ? 30 : 12); i++)
        PaperRow(id: 'r$page-$i', no: 'SR-$page-$i', status: 'confirmed', statusLabel: 'পাকা', total: first ? '100.00' : '50.00'),
    ];
    return PaperPage(
      rows: rows,
      count: 42,
      sum: '3600.00',
      pageSum: first ? '3000.00' : '600.00',
      nextPage: first ? 2 : null,
    );
  }

  @override
  Future<PaperDetail> one(String key, String id) => throw UnimplementedError();
}

DeliveryOrder _do(String id, String status, double total) => DeliveryOrder(
    id: id, no: 'DO-$id', date: '2026-10-20', customer: 'রহিম স্টোর · ফুলপুর · ময়মনসিংহ', status: status,
    statusLabel: status, total: total, editable: false, awaitingMe: false);

/// নকল ডিওর দরজা — পাতা ১: ৳1,500 আর প্রত্যাখ্যাত ৳700, পরের পাতা ২; পাতা ২: ৳250, শেষ
class _Orders implements DeliveryOrderApi {
  final pages = <int>[];

  @override
  bool get forOrders => false;

  @override
  Future<(List<DeliveryOrder>, PaperTotal?, int?)> list({bool awaitingMe = false, int page = 1}) async {
    pages.add(page);
    return page == 1
        ? ([_do('p1a', 'supervisor_pending', 1500), _do('p1b', 'rejected', 700)], const PaperTotal(sum: 1750, count: 3), 2)
        : ([_do('p2', 'draft', 250)], const PaperTotal(sum: 1750, count: 3), null);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

MoneyInRow _in(String no, double amount) => MoneyInRow(
    id: no, no: no, date: '2026-10-20', customer: 'রহিম স্টোর', account: 'নগদ', method: 'cash', amount: amount);

/// নকল আদায়ের দরজা — পাতা ১: ৳1,000 আর ৳500, পাতা ২: ৳250
class _Collections implements BooksApi {
  final pages = <int>[];

  @override
  Future<MoneyInPage> moneyIn({required String from, required String to, String? q, String? method, int page = 1}) async {
    pages.add(page);
    return page == 1
        ? MoneyInPage(rows: [_in('RV-1', 1000), _in('RV-2', 500)], total: 1750, count: 3, nextPage: 2)
        : MoneyInPage(rows: [_in('RV-3', 250)], total: 1750, count: 3);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
