import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/orders/lead_api.dart';
import 'package:abos_mobile/core/orders/paper_total.dart';
import 'package:abos_mobile/core/promotion/offers_api.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/features/books/principal_screens.dart';
import 'package:abos_mobile/features/deliveries/deliveries_screen.dart';
import 'package:abos_mobile/features/leads/lead_screens.dart';
import 'package:abos_mobile/features/loading/loading_screens.dart';
import 'package:abos_mobile/features/money/money_screens.dart';
import 'package:abos_mobile/features/promotion/offers_screen.dart';
import 'package:abos_mobile/features/stock_writes/quality_serial_screens.dart';
import 'package:abos_mobile/features/stock_writes/stock_write_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬ — মালিকের নিয়ম "প্রতিটা তালিকা তার মোট/সর্বমোট দেখায়"।
///
/// <p>দাবি: দশটা তালিকার মাথায় গোটা তালিকার পট্টি — সার্ভারের মোট বা গোনা, সব পাতার (পাতার দুই সারির যোগ নয়)।
/// ⛔ পুরনো সার্ভার চাবি না পাঠালে পট্টিই নেই — এক পাতার যোগ দেখানো মিথ্যা মোট। কেবল অপেক্ষার গণনা আর চলতি অফার
/// নিজের হাতের সারি গোনে, কারণ ও দুটো পাতা ভাঙে না — পুরো তালিকা একবারে আসে।

Finder get _strip => find.byKey(const ValueKey('books-total'));

Finder _inStrip(String text) => find.descendant(of: _strip, matching: find.text(text));

/// সার্ভার বলে ১২০টা, পাতায় আসে দুটো — পট্টি ১২০ দেখাবে, ২ নয়
const _count = 120;

Future<void> _show(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

// ── নকল সার্ভার ──────────────────────────────────────────────────────────────────────────────────

class _Money implements MoneyApi {
  _Money(this.answers);

  final Map<String, Map<String, dynamic>> answers;

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, Object?>? query}) async => answers[path] ?? const {};

  @override
  Future<MoneyResult> post(String path, Map<String, Object?> body) async => const MoneyResult('done', '');
}

class _Qs implements QualitySerialApi {
  _Qs(this.count);

  final int? count;

  @override
  Future<(List<InspectionRow>, List<StockChoice>, int?, int?)> inspections({String? status, int page = 1}) async => (
        const [InspectionRow(id: 'q1', no: 'QC-1'), InspectionRow(id: 'q2', no: 'QC-2')],
        const <StockChoice>[],
        2,
        count,
      );

  @override
  Future<(List<SerialRow>, int?, int?)> serials({String serial = '', int page = 1}) async =>
      (const [SerialRow(serialNo: 'SN-1'), SerialRow(serialNo: 'SN-2')], 2, count);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Stock implements StockWriteApi {
  _Stock(this.rows);

  final List<WaitingCount> rows;

  @override
  Future<List<WaitingCount>> counts() async => rows;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

const _run = DeliveryRunRow(token: 't', documentNo: 'CHA-1', customer: 'রহিম স্টোর');

class _Runs implements DeliveryRunApi {
  _Runs(this.count);

  final int? count;

  @override
  Future<DeliveryRunPage> today({int page = 1}) async => DeliveryRunPage(
        const [_run, DeliveryRunRow(token: 'u', documentNo: 'CHA-2', customer: 'করিম স্টোর')],
        nextPage: 2,
        count: count,
      );
}

class _Loading implements LoadingApi {
  _Loading(this.count);

  final int? count;

  @override
  Future<LoadingTripPage> trips({int page = 1}) async => LoadingTripPage(
        const [LoadingTrip(id: '1', documentNo: 'TRP-1'), LoadingTrip(id: '2', documentNo: 'TRP-2')],
        nextPage: 2,
        count: count,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

const _lead = Lead(
    id: 'l1', no: 'LD-1', name: 'নতুন মুদি', source: 'walk_in', sourceLabel: 'নিজে এসেছেন', status: 'new', statusLabel: 'নতুন', converted: false);

class _Leads implements LeadApi {
  _Leads(this.count);

  final int? count;

  @override
  Future<LeadSetup> setup() async => const LeadSetup(sources: [], statuses: []);

  @override
  Future<(List<Lead>, int?, int?)> list({String? query, String? status, int page = 1}) async => (
        const [_lead, Lead(id: 'l2', no: 'LD-2', name: 'পুরনো মুদি', source: 'walk_in', sourceLabel: 'নিজে এসেছেন', status: 'new', statusLabel: 'নতুন', converted: false)],
        2,
        count,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Books implements BooksApi {
  _Books(this.total);

  final PaperTotal? total;

  @override
  Future<(List<PrincipalRow>, int?, PaperTotal?)> principals({String? q, int page = 1}) async => (
        const [PrincipalRow(id: 'p1', name: 'Star Line', balance: 1000), PrincipalRow(id: 'p2', name: 'Akij', balance: 500)],
        2,
        total,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Offers implements OffersApi {
  @override
  Future<OffersPage> running() async => const OffersPage(enabled: true, rows: [
        Offer(id: 'o1', code: 'PROM-1', name: 'শীতের অফার', products: ['মিনিকেট চাল']),
        Offer(id: 'o2', code: 'PROM-2', name: 'তেলের ছাড়', brands: ['রূপচাঁদা']),
        Offer(id: 'o3', code: 'PROM-3', name: 'চিনির অফার', products: ['চিনি']),
      ]);
}

void main() {
  group('a total from the server, across every page', () {
    testWidgets('transfers: the total amount and count, not the two rows on the page', (tester) async {
      await _show(
          tester,
          TransferListScreen(
              api: _Money({
            '/accounts/transfers': {
              'rows': [
                {'id': 't1', 'no': 'TR-1', 'amount': '100.00'},
                {'id': 't2', 'no': 'TR-2', 'amount': '200.00'},
              ],
              'total': '987654.25',
              'count': _count,
            },
          })));

      expect(_inStrip('মোট হাতবদল'), findsOneWidget);
      expect(_inStrip(Money.taka(987654.25)), findsOneWidget);
      expect(_inStrip('· $_count টি'), findsOneWidget);
      expect(_inStrip(Money.taka(300)), findsNothing);
    });

    testWidgets('transfers: an older server without the total shows no strip', (tester) async {
      await _show(
          tester,
          TransferListScreen(
              api: _Money({
            '/accounts/transfers': {
              'rows': [
                {'id': 't1', 'no': 'TR-1', 'amount': '100.00'},
              ],
            },
          })));

      expect(find.byKey(const ValueKey('transfer-t1')), findsOneWidget);
      expect(_strip, findsNothing);
    });

    testWidgets('principals: the total payable and count from the server', (tester) async {
      await _show(tester, PrincipalListScreen(api: _Books(const PaperTotal(sum: 4567890.5, count: _count))));

      expect(_inStrip('মোট দেনা'), findsOneWidget);
      expect(_inStrip(Money.taka(4567890.5)), findsOneWidget);
      expect(_inStrip('· $_count টি'), findsOneWidget);
      expect(_inStrip(Money.taka(1500)), findsNothing);
    });

    testWidgets('principals: an older server without the total shows no strip', (tester) async {
      await _show(tester, PrincipalListScreen(api: _Books(null)));

      expect(find.byKey(const ValueKey('principal-p1')), findsOneWidget);
      expect(_strip, findsNothing);
    });
  });

  group('a count from the server, across every page', () {
    final screens = <String, Widget Function(int? count)>{
      'মোট গোনা': (count) => CashCountListScreen(
              api: _Money({
            '/accounts/cash-counts': {
              'rows': [
                {'id': 'c1', 'no': 'CC-1'},
                {'id': 'c2', 'no': 'CC-2'},
              ],
              if (count != null) 'count': count,
            },
          })),
      'মোট যাচাই': (count) => QualityListScreen(api: _Qs(count)),
      'মোট সিরিয়াল': (count) => SerialScreen(api: _Qs(count)),
      'পথে মোট চালান': (count) => DeliveriesScreen(api: _Runs(count)),
      'মোট লোডিং শিট': (count) => LoadingListScreen(api: _Loading(count)),
      'মোট লিড': (count) => LeadListScreen(api: _Leads(count)),
    };

    for (final screen in screens.entries) {
      testWidgets('${screen.key}: the server\'s count, not the two rows on the page', (tester) async {
        await _show(tester, screen.value(_count));

        expect(_inStrip(screen.key), findsOneWidget);
        expect(_inStrip('$_count টি'), findsOneWidget);
        expect(_inStrip('2 টি'), findsNothing);
      });

      testWidgets('${screen.key}: an older server without the count shows no strip', (tester) async {
        await _show(tester, screen.value(null));

        expect(_strip, findsNothing);
      });
    }
  });

  group('a list that comes whole counts its own rows', () {
    testWidgets('waiting stock counts: every row the server sent, and none when there are none', (tester) async {
      await _show(
          tester,
          WaitingCountsScreen(
              api: _Stock(const [
                WaitingCount(id: 'a', no: 'CNT-1'),
                WaitingCount(id: 'b', no: 'CNT-2'),
                WaitingCount(id: 'c', no: 'CNT-3'),
              ]),
              setup: const StockSetup()));

      expect(_inStrip('অপেক্ষায় মোট'), findsOneWidget);
      expect(_inStrip('3 টি'), findsOneWidget);

      await _show(tester, WaitingCountsScreen(key: const ValueKey('none'), api: _Stock(const []), setup: const StockSetup()));
      expect(_strip, findsNothing);
    });

    testWidgets('running offers: the rows shown, and the search narrows the figure with the list', (tester) async {
      await _show(tester, OffersScreen(api: _Offers(), today: DateTime(2026, 10, 18)));

      expect(_inStrip('চলতি অফার'), findsOneWidget);
      expect(_inStrip('3 টি'), findsOneWidget);

      await tester.enterText(find.byKey(const ValueKey('offer-search')), 'তেল');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('offer-o2')), findsOneWidget);
      expect(_inStrip('1 টি'), findsOneWidget);
    });
  });
}
