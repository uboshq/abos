import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:abos_mobile/core/orders/sales_return_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/row_icon.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/direct_sale/counter_screen.dart';
import 'package:abos_mobile/features/direct_sale/return_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ কাউন্টার, ফেরত, টাকা আদায় আর বকেয়া তালিকা নকশা ৩ মানে (a4-এর নিয়ম ০.৪.৩৮; মালিক, ১৯ অক্টোবর ২০২৬: "100% international
/// standard and enterprise grade")। আলো আর অন্ধকার দুই থিমে, ৩৬০dp ফোনে:
/// - লোড হতে ধূসর খসড়া ([LoadingSkeleton]), ঘোরানো চাকা নয়;
/// - খালি হলে [EmptyState] আর তার কাজের বোতাম ("আদায় নিন", "নতুন করে দেখুন", "নতুন বিল লিখুন");
/// - ভুল হলে [ErrorState] আর "আবার চেষ্টা করুন" — চাপলে নকল সার্ভারকে আবার ডাকে, তারপর তথ্য দেখায়;
/// - সারিতে [RowIcon], গ্রাহক "নাম · পয়েন্ট · শাখা" ([CustomerLine], কখনো খালি নাম নয়), কাগজের নম্বর দ্বিতীয় লাইনে;
/// - নিচের [TotalsBar]: "এই পাতা" = দেখানো সারি, "সর্বমোট" = সার্ভারের পুরো তালিকার `count` আর `total` — ফোন যোগ করে না;
/// - কাউন্টারে বাছা ক্রেতার লাইনে পয়েন্ট আর শাখা।

final _themes = <String, ThemeData Function()>{'আলো': AppTheme.light, 'অন্ধকার': AppTheme.dark};

Future<void> _pump(WidgetTester tester, ThemeData theme, Widget screen) async {
  tester.view.physicalSize = const Size(360, 2000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const SizedBox());
  await tester.pumpWidget(MaterialApp(theme: theme, home: screen));
}

const _point = 'মুক্তাগাছা';
const _branch = 'SL-Super Group';
const _label = 'রহিম ট্রেডার্স · $_point · $_branch';

/// সার্ভারের আসল গড়ন থেকে — `customer`, `customer_point`, `customer_branch` আলাদা ঘরে; জোড়া লাগায় অ্যাপের পার্সার
final _rows = [
  MoneyInRow.fromJson(const {
    'id': 'c1', 'no': 'RV-2610-0142', 'date': '2026-10-19', 'customer': 'রহিম ট্রেডার্স',
    'customer_point': _point, 'customer_branch': _branch, 'account': 'প্রধান নগদ', 'method': 'cash', 'amount': '48500.0000',
  }),
  MoneyInRow.fromJson(const {
    'id': 'c2', 'no': 'RV-2610-0141', 'date': '2026-10-18', 'customer': 'করিম স্টোর',
    'customer_point': 'ফুলপুর', 'customer_branch': _branch, 'account': 'DBBL', 'method': 'cheque', 'amount': '125000.0000',
  }),
];

/// ⓘ সর্বমোট ইচ্ছে করে দেখানো সারির যোগফল (1,73,500) নয় — ফোন নিজে যোগ করলে ধরা পড়ে
final _full = MoneyInPage(rows: _rows, total: 1842650, count: 37, nextPage: 2);
const _none = MoneyInPage(rows: [], total: 0, count: 0);

/// নকল আদায়ের দরজা: প্রতিটা ডাকে তালিকার পরের উত্তর (Completer হলে ঝুলে থাকে, Exception হলে ছোড়ে); কতবার ডাকা হলো গোনে
class _Books implements BooksApi {
  _Books(this.answers);

  final List<Object> answers;
  int calls = 0;

  @override
  Future<MoneyInPage> moneyIn({required String from, required String to, String? q, String? method, int page = 1}) async {
    final a = answers[calls < answers.length ? calls : answers.length - 1];
    calls++;
    if (a is Completer<MoneyInPage>) return a.future;
    if (a is Exception) throw a;
    return a as MoneyInPage;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

const _setup = CounterSetup(
  warehouseId: 'wh-1',
  warehouses: [CounterChoice('wh-1', 'প্রধান গুদাম')],
  paymentTerms: [CounterChoice('cash', 'নগদ')],
  moneyAccounts: [CounterChoice('ac-1', '1101 · নগদ', kind: 'cash')],
  lots: {},
);

final _drafts = [
  CounterDraftSummary.fromJson(const {
    'id': 'drf-1', 'no': 'DRF-0014', 'customer': 'রহিম ট্রেডার্স', 'customer_point': _point,
    'customer_branch': _branch, 'total': '4820.00', 'date': '2026-10-19', 'lines': 3,
  }),
];

class _Counter implements DirectSaleApi {
  _Counter({this.setups = const [_setup], this.draftAnswers = const []});

  final List<Object> setups;
  final List<Object> draftAnswers;
  int setupCalls = 0;
  int draftCalls = 0;

  static Future<T> _answer<T>(List<Object> list, int i) async {
    final a = list[i < list.length ? i : list.length - 1];
    if (a is Completer<T>) return a.future;
    if (a is Exception) throw a;
    return a as T;
  }

  @override
  Future<CounterSetup> setup({String? warehouseId}) => _answer<CounterSetup>(setups, setupCalls++);

  @override
  Future<List<CounterDraftSummary>> drafts() => _answer<List<CounterDraftSummary>>(draftAnswers, draftCalls++);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Returns implements SalesReturnApi {
  _Returns(this.setups);

  final List<Object> setups;
  int calls = 0;

  @override
  Future<ReturnSetup> setup({String? customerId}) async {
    final a = setups[calls < setups.length ? calls : setups.length - 1];
    calls++;
    if (a is Completer<ReturnSetup>) return a.future;
    if (a is Exception) throw a;
    return a as ReturnSetup;
  }

  @override
  Future<ReturnBill> bill(String invoiceId) async => ReturnBill.fromJson(const {
        'id': 'inv-1', 'no': 'INV-2610-0391', 'customer': 'cus-1', 'customerName': 'রহিম ট্রেডার্স',
        'customerPoint': _point, 'customerBranch': _branch, 'lines': [],
      });

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

final _returnSetup = ReturnSetup.fromJson(const {
  'reasons': [
    {'id': 'rsn-1', 'label': 'নষ্ট মাল'}
  ],
  'invoices': [
    {'id': 'inv-1', 'no': 'INV-2610-0391', 'customer': 'রহিম ট্রেডার্স', 'customer_point': _point,
      'customer_branch': _branch, 'total': '48500.0000', 'date': '2026-10-19'},
  ],
});
const _noBills = ReturnSetup(reasons: [], invoices: []);

final _down = Exception('নেট নেই');

/// সারির নাম আর দ্বিতীয় লাইন: নাম [CustomerLine]-এ, নম্বর তার নিচে একই সারিতে
void _expectRow(WidgetTester tester, Key rowKey, String name, String numberStart) {
  final row = find.byKey(rowKey);
  expect(row, findsOneWidget);
  expect(find.descendant(of: row, matching: find.byType(RowIcon)), findsOneWidget, reason: 'বাঁয়ে ৪০dp আইকন');
  final line = find.descendant(of: row, matching: find.byType(CustomerLine));
  expect(tester.widget<CustomerLine>(line).text, name, reason: '⛔ খালি নাম নয় — "নাম · পয়েন্ট · শাখা"');
  final number = find.descendant(of: row, matching: find.textContaining(numberStart));
  expect(number, findsOneWidget);
  expect(tester.getTopLeft(number).dy, greaterThan(tester.getTopLeft(line).dy), reason: 'নম্বর দ্বিতীয় লাইনে');
  expect(tester.getSize(row).height, greaterThanOrEqualTo(72), reason: 'সারি অন্তত ৭২dp');
}

/// ০.৪.৪১-এর কাগজের সারি: প্রথম লাইনে "নম্বর · নাম", নিচে বাকি "পয়েন্ট · শাখা", ডানে চিপ; সারি অন্তত ৫৬dp
void _expectPaperRow(WidgetTester tester, Key rowKey, String label, String no) {
  final row = find.byKey(rowKey);
  expect(row, findsOneWidget);
  final at = label.indexOf(' · ');
  // ⓘ প্রথম লাইন: নম্বর নিজের লেখা (কখনো কাটে না), নাম পাশে ([NumberAndName])
  final line = find.descendant(of: row, matching: find.byType(NumberAndName));
  expect(tester.widget<NumberAndName>(line).number, no);
  expect(tester.widget<NumberAndName>(line).name, at < 0 ? label : label.substring(0, at));
  if (at >= 0) {
    final place = find.descendant(of: row, matching: find.textContaining(label.substring(at + 3)));
    expect(place, findsOneWidget, reason: '⛔ পয়েন্ট · শাখা বাদ নয়');
    expect(tester.getTopLeft(place).dy, greaterThan(tester.getTopLeft(line).dy));
  }
  final chip = find.descendant(of: row, matching: find.byType(AppStatusChip));
  expect(tester.getRect(chip).left, greaterThan(tester.getRect(line).left), reason: 'চিপ ডানে');
  expect(tester.getSize(row).height, greaterThanOrEqualTo(56));
}

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async => harness.tearDown());

  setUp(() async {
    ReferenceSync.forgetLastFailure();
    await ReferenceCache.instance.clearAll();
  });

  for (final MapEntry(key: mode, value: theme) in _themes.entries) {
    group(mode, () {
      // ── টাকা আদায় ──────────────────────────────────────────────────────────────
      testWidgets('আদায়: খসড়া সারি → সারি; নাম · পয়েন্ট · শাখা, নম্বর দ্বিতীয় লাইনে; TotalsBar সার্ভারের অঙ্ক', (tester) async {
        final wait = Completer<MoneyInPage>();
        final api = _Books([wait]);
        await _pump(tester, theme(), MoneyInListScreen(api: api, today: DateTime(2026, 10, 19)));
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsOneWidget, reason: 'প্রথম আনায় খসড়া সারি, ঘোরানো চাকা নয়');
        expect(find.byType(CircularProgressIndicator), findsNothing);

        wait.complete(_full);
        await tester.pumpAndSettle();
        expect(find.byType(LoadingSkeleton), findsNothing);
        // ⭐ ০.৪.৪১ (মালিকের অনুমোদিত পুরো-পাতার নমুনা, ২০ অক্টোবর ২০২৬; a4): আদায়ের সারি এখন বাঁয়ে "নম্বর · নাম", নিচে
        // "পয়েন্ট · শাখা", ডানে টাকা আর a6-এর চিপ — নকশা ৩-এর "নাম আগে, নম্বর দ্বিতীয় লাইনে" এই পাতায় আর নয়
        _expectPaperRow(tester, const ValueKey('money-in-RV-2610-0142'), _label, 'RV-2610-0142');
        _expectPaperRow(tester, const ValueKey('money-in-RV-2610-0141'), 'করিম স্টোর · ফুলপুর · $_branch', 'RV-2610-0141');
        expect(find.text('৳48,500.00'), findsOneWidget, reason: 'টাকা ডানে, Money.taka');

        final bar = tester.widget<TotalsBar>(find.byType(TotalsBar));
        expect([bar.pageCount, bar.pageSum, bar.allCount, bar.allSum], [2, 173500, 37, 1842650],
            reason: '"এই পাতা" দেখানো সারির, "সর্বমোট" সার্ভারের count আর total');
        expect(find.text('সর্বমোট · 37টি'), findsOneWidget);
        expect(find.text('৳18,42,650.00'), findsOneWidget);
        expect(find.text('৳1,73,500.00'), findsOneWidget);
        expect(tester.takeException(), isNull);
      });

      // ⛔ ২০ নভেম্বর ২০২৬: ফোনে আদায় লেখা নেই — খালি তালিকার কাজ "টাকা জমার খবর"
      testWidgets('আদায়: খালি হলে "টাকা জমার খবর"; দেখার লোকের "নতুন করে দেখুন" সার্ভারকে আবার ডাকে', (tester) async {
        await _pump(tester, theme(), MoneyInListScreen(api: _Books([_none]), canReportDeposit: true, today: DateTime(2026, 10, 19)));
        await tester.pumpAndSettle();
        expect(find.byType(EmptyState), findsOneWidget);
        expect(find.byKey(const ValueKey('money-in-empty-deposit-report')), findsOneWidget);
        expect(find.descendant(of: find.byKey(const ValueKey('money-in-empty-deposit-report')), matching: find.text('টাকা জমার খবর')),
            findsOneWidget);
        expect(find.text('আদায় নিন'), findsNothing, reason: '⛔ ফোনে আবার আদায় নেওয়ার পথ');
        expect(find.byType(TotalsBar), findsNothing, reason: 'খালি তালিকায় শূন্যের পট্টি নয়');
        expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget,
            reason: 'খালি তালিকাও টেনে নতুন করা যায়');

        final api = _Books([_none, _full]);
        await _pump(tester, theme(), MoneyInListScreen(api: api, today: DateTime(2026, 10, 19)));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('money-in-empty-refresh')));
        await tester.pumpAndSettle();
        expect(api.calls, 2);
        expect(find.byKey(const ValueKey('money-in-RV-2610-0142')), findsOneWidget);
      });

      testWidgets('আদায়: ভুলে "আবার চেষ্টা করুন" — চাপলে আবার ডাকে, তারপর সারি', (tester) async {
        final api = _Books([_down, _full]);
        await _pump(tester, theme(), MoneyInListScreen(api: api, today: DateTime(2026, 10, 19)));
        await tester.pumpAndSettle();
        expect(find.byType(ErrorState), findsOneWidget);
        expect(find.byType(EmptyState), findsNothing, reason: '⛔ ভুলকে "আদায় নেই" বলা নয়');

        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pumpAndSettle();
        expect(api.calls, 2);
        expect(find.byType(ErrorState), findsNothing);
        expect(find.byKey(const ValueKey('money-in-RV-2610-0142')), findsOneWidget);
      });

      // ── বকেয়া তালিকা ────────────────────────────────────────────────────────────
      testWidgets('বকেয়া: সারি নাম · পয়েন্ট · শাখা, নম্বর দ্বিতীয় লাইনে; খুঁজলে "এই পাতা" কমে, "সর্বমোট" নয়', (tester) async {
        await tester.runAsync(() async {
          for (final (id, name, point, due) in [
            ('c1', 'রহিম ট্রেডার্স', _point, '48500.0000'),
            ('c2', 'করিম স্টোর', 'ফুলপুর', '125000.0000'),
          ]) {
            await ReferenceCache.instance.put(entityType: 'Customer', entityId: id, updatedAt: DateTime(2026, 10, 19),
                payload: {'id': id, 'code': 'C-00$id', 'nameBn': name, 'pointName': point, 'branchName': _branch});
            await ReferenceCache.instance.put(entityType: 'CustomerDue', entityId: id, updatedAt: DateTime(2026, 10, 19),
                payload: {'customerId': id, 'outstanding': due, 'creditLimit': '0', 'creditDays': 0});
          }
        });
        await _pump(tester, theme(), const DueListScreen());
        await tester.pump();

        _expectRow(tester, const ValueKey('due-c1'), _label, 'C-00c1');
        _expectRow(tester, const ValueKey('due-c2'), 'করিম স্টোর · ফুলপুর · $_branch', 'C-00c2');
        var bar = tester.widget<TotalsBar>(find.byType(TotalsBar));
        expect([bar.pageCount, bar.pageSum, bar.allCount, bar.allSum], [2, 173500, 2, 173500]);

        await tester.enterText(find.byType(TextField), 'রহিম');
        await tester.pump();
        bar = tester.widget<TotalsBar>(find.byType(TotalsBar));
        expect([bar.pageCount, bar.pageSum, bar.allCount, bar.allSum], [1, 48500, 2, 173500],
            reason: 'সর্বমোট পুরো তালিকার — খোঁজায় লুকানো দোকানের বকেয়াও তাতে');
        expect(tester.takeException(), isNull);
      });

      testWidgets('বকেয়া: খালিতে "নতুন করে দেখুন" — চলাকালীন খসড়া সারি; ভুলে "আবার চেষ্টা করুন" আবার টানে', (tester) async {
        var pulls = 0;
        Completer<void>? hold;
        Future<void> refresh() async {
          pulls++;
          if (hold != null) await hold.future;
        }

        await _pump(tester, theme(), DueListScreen(refresh: refresh));
        await tester.pump();
        expect(find.byType(EmptyState), findsOneWidget);
        expect(find.text('কারো কাছে বকেয়া নেই'), findsOneWidget);

        hold = Completer<void>();
        await tester.tap(find.byKey(const ValueKey('due-empty-refresh')));
        await tester.pump();
        expect(pulls, 1);
        expect(find.byType(LoadingSkeleton), findsOneWidget, reason: 'টানা চলাকালীন খসড়া সারি');
        hold.complete();
        await tester.pump();
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsNothing);

        ReferenceSync.rememberFailureForTest(SyncAttemptFailure.from(Exception('৫০৩'), direction: SyncDirection.pull));
        hold = null;
        await _pump(tester, theme(), DueListScreen(refresh: refresh));
        await tester.pump();
        expect(find.byType(ErrorState), findsOneWidget);
        expect(find.text('কারো কাছে বকেয়া নেই'), findsNothing, reason: '⛔ ব্যর্থ টানে "কেউ বাকি নেই" নয়');
        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pump();
        expect(pulls, 2);
      });

      // ── কাউন্টার ───────────────────────────────────────────────────────────────
      testWidgets('কাউন্টার: বাছা ক্রেতার লাইনে পয়েন্ট আর শাখা, বাছার তালিকাতেও', (tester) async {
        await _pump(tester, theme(), CounterScreen(
          api: _Counter(),
          customers: const [
            CustomerRecord({'id': 'cus-1', 'nameBn': 'রহিম ট্রেডার্স', 'pointName': _point, 'branchName': _branch}),
          ],
          products: const [],
        ));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('counter-customer')));
        await tester.pumpAndSettle();
        expect(find.widgetWithText(CustomerLine, _label), findsOneWidget, reason: 'বাছার তালিকায় "নাম · পয়েন্ট · শাখা"');
        await tester.tap(find.widgetWithText(CustomerLine, _label));
        await tester.pumpAndSettle();
        expect(tester.widget<CustomerLine>(find.byKey(const ValueKey('counter-customer-line'))).text, _label);
        expect(tester.takeException(), isNull);
      });

      testWidgets('কাউন্টার: খুলতে খসড়া সারি; খোলা না গেলে "আবার চেষ্টা করুন" আবার ডাকে', (tester) async {
        final wait = Completer<CounterSetup>();
        final api = _Counter(setups: [wait, _setup]);
        await _pump(tester, theme(), CounterScreen(api: api, customers: const [], products: const []));
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsOneWidget);
        wait.completeError(_down);
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('counter-setup-error')), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pumpAndSettle();
        expect(api.setupCalls, 2);
        expect(find.byKey(const ValueKey('counter-setup-error')), findsNothing);
      });

      testWidgets('কাউন্টার: খসড়ার তালিকা — খসড়া সারি, ভুলে আবার চেষ্টা, নকশার সারি; খালিতে "নতুন বিল লিখুন"', (tester) async {
        final wait = Completer<List<CounterDraftSummary>>();
        final api = _Counter(draftAnswers: [wait, _drafts]);
        await _pump(tester, theme(), CounterScreen(api: api, customers: const [], products: const []));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('counter-open-draft')));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 500));
        expect(find.descendant(of: find.byKey(const ValueKey('counter-drafts')), matching: find.byType(LoadingSkeleton)),
            findsOneWidget);
        wait.completeError(_down);
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('counter-drafts-error')), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('error-retry')).last);
        await tester.pumpAndSettle();
        expect(api.draftCalls, 2);
        _expectRow(tester, const ValueKey('counter-draft-drf-1'), _label, 'DRF-0014');
        expect(find.text('৳4,820.00'), findsOneWidget);
        expect(find.byWidgetPredicate((w) => w is AppStatusChip && w.state == PaperState.draft), findsOneWidget, reason: 'অবস্থার চিপ');

        final empty = _Counter(draftAnswers: [<CounterDraftSummary>[]]);
        await _pump(tester, theme(), CounterScreen(api: empty, customers: const [], products: const []));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('counter-open-draft')));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('counter-drafts-empty')), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('counter-drafts-new')));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('counter-drafts')), findsNothing, reason: 'কাজের বোতাম পাতা বন্ধ করে কাউন্টারে ফেরায়');
      });

      // ── ফেরত ───────────────────────────────────────────────────────────────────
      testWidgets('ফেরত: খসড়া সারি → ভুল → আবার চেষ্টা → বিলের সারি নাম · পয়েন্ট · শাখা; বাছা বিলে ক্রেতার লাইন', (tester) async {
        final wait = Completer<ReturnSetup>();
        final api = _Returns([wait, _returnSetup]);
        await _pump(tester, theme(), ReturnScreen(customerId: 'cus-1', api: api));
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsOneWidget);
        wait.completeError(_down);
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('return-load-error')), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pumpAndSettle();
        expect(api.calls, 2);
        _expectRow(tester, const ValueKey('return-bill-inv-1'), _label, 'INV-2610-0391');
        expect(find.text('৳48,500.00'), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('return-bill-inv-1')));
        await tester.pumpAndSettle();
        expect(tester.widget<CustomerLine>(find.byKey(const ValueKey('return-bill-customer'))).text, _label);
        expect(tester.getTopLeft(find.byKey(const ValueKey('return-bill-no'))).dy,
            greaterThan(tester.getTopLeft(find.byKey(const ValueKey('return-bill-customer'))).dy),
            reason: 'বিলের নম্বর দ্বিতীয় লাইনে');
        expect(tester.takeException(), isNull);
      });

      testWidgets('ফেরত: বিল না থাকলে খালি আর "নতুন করে দেখুন" আবার ডাকে', (tester) async {
        final api = _Returns([_noBills, _returnSetup]);
        await _pump(tester, theme(), ReturnScreen(api: api));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('return-empty')), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('return-empty-refresh')));
        await tester.pumpAndSettle();
        expect(api.calls, 2);
        expect(find.byKey(const ValueKey('return-bill-inv-1')), findsOneWidget);
      });
    });
  }
}
