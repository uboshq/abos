import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/paper_kinds.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/money/money_screens.dart';
import 'package:abos_mobile/features/papers/paper_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬ (fe): টাকার হাতবদল, নগদ গোনা, হাতধার আর অর্থের পাতা ফোনের নিজের পর্দায়।
/// নিয়ম সার্ভারের — ফোন ঘর ভরে পাঠায়, উত্তর বলে, আর সার্ভার যা দেয় কেবল সেই বোতাম দেখায়।
class _FakeMoney implements MoneyApi {
  _FakeMoney({this.empty = false, this.mine = true, this.canApprove = true});

  final bool empty;
  final bool mine;
  final bool canApprove;
  final List<String> asked = [];
  final List<(String, Map<String, Object?>)> posted = [];

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, Object?>? query}) async {
    asked.add(path);
    final transfer = {'id': 'mt-1', 'no': 'MT-0007', 'date': '17/10/2026', 'from': 'কাউন্টার', 'to': 'সিন্দুক', 'amount': '4000.00',
        'status': 'draft', 'status_label': 'নেওয়ার অপেক্ষায়', 'mine_to_receive': mine};
    return switch (path) {
      '/accounts/transfers' => {'awaiting': empty ? [] : [transfer], 'rows': empty ? [] : [transfer]},
      '/accounts/transfers/mt-1' => transfer,
      '/accounts/money/setup' => {
          'tills': [
            {'id': 't-1', 'name': 'কাউন্টার', 'balance': '10,000.00'},
          ],
          'destinations': [
            {'id': 'till:t-1', 'name': 'কাউন্টার'},
            {'id': 'till:t-2', 'name': 'সিন্দুক'},
          ],
          'people': [
            {'id': 'u-2', 'name': 'করিম'},
          ],
        },
      '/accounts/cash-counts/setup' => {
          'tills': [
            {'id': 't-1', 'name': 'কাউন্টার', 'balance': '10,000.00'},
          ],
          'notes': [1000, 500],
        },
      '/accounts/cash-counts' => {
          'rows': empty
              ? []
              : [
                  {'id': 'cc-1', 'no': 'CC-0003', 'till': 'কাউন্টার', 'counted': '9,500.00', 'difference': '-500.00', 'status_label': 'খসড়া'},
                ],
        },
      '/accounts/cash-counts/cc-1' => {'id': 'cc-1', 'no': 'CC-0003', 'till': 'কাউন্টার', 'counted': '9,500.00', 'expected': '10,000.00',
          'difference': '-500.00', 'status_label': 'খসড়া', 'can_approve': canApprove, 'notes': [{'note': 1000, 'qty': 9}, {'note': 500, 'qty': 1}]},
      '/finance/hand-loans/money-accounts' => {
          'rows': [
            {'id': 'a-1', 'name': '1101 নগদ'},
          ],
        },
      '/finance/cfo' => {
          'figures': [
            {'label': 'নগদ', 'value': '10,000.00'},
            {'label': 'ব্যাংক', 'value': '50,000.00'},
          ],
          'liquidity': {'code': 'good', 'label': 'ভালো', 'rule': 'চলতি অনুপাত ২-এর বেশি'},
          'in_30_days': '45,000.00',
          'budget': null,
        },
      _ => <String, dynamic>{},
    };
  }

  @override
  Future<MoneyResult> post(String path, Map<String, Object?> body) async {
    posted.add((path, body));
    return const MoneyResult('done', 'বসেছে।');
  }
}

void main() {
  testWidgets('a transfer waiting for me can be received; one sent by me only cancelled', (tester) async {
    final api = _FakeMoney();
    await tester.pumpWidget(MaterialApp(home: TransferScreen(id: 'mt-1', api: api)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('transfer-confirm')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('transfer-confirm')));
    await tester.pumpAndSettle();
    expect(api.posted.single.$1, '/accounts/transfers/mt-1/confirm');

    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(home: TransferScreen(id: 'mt-1', api: _FakeMoney(mine: false))));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('transfer-confirm')), findsNothing, reason: 'পাঠানো মানুষ নিজে "পেলাম" চাপতে পারলেন');
    expect(find.byKey(const ValueKey('transfer-cancel')), findsOneWidget);
  });

  testWidgets('sending a transfer sends the till, the destination and the amount by their ids', (tester) async {
    final api = _FakeMoney();
    await tester.pumpWidget(MaterialApp(home: SendTransferScreen(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('transfer-send')));
    await tester.pumpAndSettle();
    expect(api.posted, isEmpty, reason: 'খালি ফর্ম পাঠানো গেল');

    await tester.tap(find.byKey(const ValueKey('transfer-to')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('সিন্দুক').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('transfer-amount')), '4000');
    await tester.tap(find.byKey(const ValueKey('transfer-send')));
    await tester.pumpAndSettle();

    expect(api.posted.single.$1, '/accounts/transfers');
    expect(api.posted.single.$2['from_till'], 't-1');
    expect(api.posted.single.$2['destination'], 'till:t-2');
    expect(api.posted.single.$2['amount'], '4000');
  });

  testWidgets('a cash count sends the notes per size, and only someone else may settle it', (tester) async {
    final api = _FakeMoney();
    await tester.pumpWidget(MaterialApp(home: NewCashCountScreen(api: api)));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('count-note-1000')), '9');
    await tester.enterText(find.byKey(const ValueKey('count-note-500')), '1');
    await tester.tap(find.byKey(const ValueKey('count-send')));
    await tester.pumpAndSettle();
    expect(api.posted.single.$2, {'till': 't-1', 'trx_date': isA<String>(), 'counts': {'1000': 9, '500': 1}});

    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(home: CashCountScreen(id: 'cc-1', api: _FakeMoney(canApprove: false))));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('count-approve')), findsNothing, reason: 'যিনি গুনেছেন তাঁকে "মেনে নিন" দেখাল');
    expect(find.text('1000 টাকার নোট'), findsOneWidget);
  });

  testWidgets('a hand loan given out sends its direction, account and amount', (tester) async {
    final api = _FakeMoney();
    await tester.pumpWidget(MaterialApp(home: HandLoanMoveScreen(id: 'hl-1', name: 'রহিম', api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('handloan-account')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('1101 নগদ').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('handloan-amount')), '700');
    await tester.tap(find.byKey(const ValueKey('handloan-send')));
    await tester.pumpAndSettle();

    expect(api.posted.single.$1, '/finance/hand-loans/hl-1/move');
    expect(api.posted.single.$2['direction'], 'out');
    expect(api.posted.single.$2['money_account'], 'a-1');
    expect(api.posted.single.$2['amount'], '700');
  });

  testWidgets('the CFO page draws the server figures, and an empty list pulls again', (tester) async {
    await tester.pumpWidget(MaterialApp(home: CfoScreen(api: _FakeMoney())));
    await tester.pumpAndSettle();
    expect(find.text('৳45,000.00'), findsOneWidget);
    expect(find.text('50,000.00'), findsOneWidget);

    final empty = _FakeMoney(empty: true);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(home: TransferListScreen(api: empty)));
    await tester.pumpAndSettle();
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    final before = empty.asked.length;
    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(empty.asked.length, greaterThan(before));
  });

  test('the money web paths open phone screens, the new papers their own list', () {
    for (final path in NativeForWeb.moneyDesks) {
      expect(NativeForWeb.screenFor(path), isA<ModuleGateView>(), reason: path);
      expect(NativeForWeb.iconFor(path), isNotNull, reason: path);
    }
    for (final entry in const {
      '/accounts/inter-company': 'accounts_inter_company',
      '/finance/capital': 'finance_capital',
      '/finance/insurance': 'finance_insurance',
    }.entries) {
      final screen = NativeForWeb.screenFor(entry.key) as ModuleGateView;
      expect((screen.child as PaperListScreen).paperKey, entry.value);
      expect(PaperKinds.all.containsKey(entry.value), isTrue);
    }
    expect(NativeForWeb.screenFor('/finance/profit'), isNull, reason: 'লাভ ঘোষণা ওয়েবেই থাকার কথা');
  });
}
