import 'package:abos_mobile/core/accounts/account_ledger_api.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/accounts_chart/chart_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeApi implements AccountLedgerApi {
  final asked = <String>[];

  @override
  Future<List<ChartAccount>> chart({String q = ''}) async {
    asked.add('chart:$q');
    return const [
      ChartAccount({'id': 'g1', 'code': '1000', 'name': 'সম্পদ', 'is_group': true, 'depth': 0, 'balance': '(Dr) 9,250.00'}),
      ChartAccount({'id': 'a1', 'code': '1985', 'name': 'ফোনের খাতা', 'is_group': false, 'parent': 'g1', 'depth': 1, 'balance': '(Dr) 4,250.00'}),
    ];
  }

  @override
  Future<AccountLedgerPage> ledger(String id, {int page = 1}) async {
    asked.add('ledger:$id:$page');
    return AccountLedgerPage({
      'account': {'id': id, 'code': '1985', 'name': 'ফোনের খাতা', 'is_group': false, 'balance': '(Dr) 4,250.00'},
      'entries': [
        page == 1
            ? {'date': '01-09-2026', 'document_no': 'JV-0001', 'narration': 'প্রথম', 'debit': '100.00', 'credit': '', 'balance': '(Dr) 100.00'}
            : {'date': '30-09-2026', 'document_no': 'JV-0053', 'narration': 'শেষ', 'debit': '', 'credit': '250.00', 'balance': '(Dr) 4,250.00'},
      ],
      'totals': {'debit': '5,000.00', 'credit': '750.00', 'closing': '(Dr) 4,250.00'},
      'count': 53,
      'next_page': page == 1 ? 2 : null,
    });
  }
}

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay"* — হিসাবের ছক আর খাতা ফোনের নিজের পর্দায়, নিচে
/// নয়, উপরেই সব পাতার ডেবিট, ক্রেডিট আর শেষ জের।
class _EmptyApi implements AccountLedgerApi {
  const _EmptyApi();

  @override
  Future<List<ChartAccount>> chart({String q = ''}) async => const [];

  @override
  Future<AccountLedgerPage> ledger(String id, {int page = 1}) async => const AccountLedgerPage({
        'account': {'id': 'a1', 'is_group': false},
        'entries': [],
        'totals': {'debit': '0.00', 'credit': '0.00', 'closing': '0.00'},
        'count': 0,
      });
}

void main() {
  testWidgets('an empty chart and an empty ledger can both be pulled to refresh', (tester) async {
    for (final screen in [
      const ChartOfAccountsScreen(api: _EmptyApi()),
      const AccountLedgerScreen(id: 'a1', name: 'খাত', api: _EmptyApi()),
    ]) {
      await tester.pumpWidget(MaterialApp(home: screen));
      await tester.pumpAndSettle();
      expect(find.byType(EmptyState), findsOneWidget, reason: 'খালি অবস্থা আঁকেনি — নিচের দেখা ফাঁকা');
      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget,
          reason: 'খালি পর্দায় টান কাজ করে না');
    }
  });

  testWidgets('the chart lists each account with its Dr/Cr balance and a count, and opens its ledger', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: ChartOfAccountsScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.text('মোট 2 টি হিসাব'), findsOneWidget);
    expect(find.text('1985 · ফোনের খাতা'), findsOneWidget);
    expect(find.text('(Dr) 4,250.00'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('chart-search')), 'ফোনের');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.asked.last, 'chart:ফোনের');

    await tester.tap(find.byKey(const ValueKey('chart-a1')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'ledger:a1:1');
    expect(find.byKey(const ValueKey('ledger-totals')), findsOneWidget);
  });

  testWidgets('the ledger shows the whole debit, credit and closing balance, each line, and pages on', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: AccountLedgerScreen(id: 'a1', name: '1985 · ফোনের খাতা', api: api)));
    await tester.pumpAndSettle();

    final totals = find.byKey(const ValueKey('ledger-totals'));
    expect(find.descendant(of: totals, matching: find.text('সর্বমোট · 53 টি এন্ট্রি')), findsOneWidget);
    expect(find.descendant(of: totals, matching: find.text('5,000.00')), findsOneWidget);
    expect(find.descendant(of: totals, matching: find.text('750.00')), findsOneWidget);
    expect(find.descendant(of: totals, matching: find.text('(Dr) 4,250.00')), findsOneWidget);

    expect(find.text('01-09-2026 · JV-0001'), findsOneWidget);
    expect(find.text('ডেবিট 100.00'), findsOneWidget);
    expect(find.text('(Dr) 100.00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('ledger-more')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'ledger:a1:2');
    expect(find.text('ক্রেডিট 250.00'), findsOneWidget);
    expect(find.text('01-09-2026 · JV-0001'), findsOneWidget, reason: 'আগের পাতার সারি থাকে');
    expect(find.byKey(const ValueKey('ledger-more')), findsNothing);
  });
}
