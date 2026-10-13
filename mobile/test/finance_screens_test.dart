import 'package:abos_mobile/core/finance/finance_api.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/features/finance/finance_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অর্থ, ফোনে — জমা, ব্যাংকের ঋণ, ভাড়া, হাতধার (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)। প্রতিটা অঙ্ক সার্ভারের; ফোন কেবল
/// সাজায় আর বাংলায় বলে — মেয়াদের দিন, পরের কিস্তি, বাকি ভাড়া, কে কাকে দেবেন।
class _FakeFinance implements FinanceApi {
  final List<String> asked = [];
  bool fail = false;

  @override
  Future<FinancePage<Deposit>> deposits({String tab = 'active', int page = 1}) async {
    asked.add('deposits:$tab:$page');
    if (fail) throw Exception('boom');
    if (tab == 'closed') return const FinancePage([]);
    if (page == 2) {
      return const FinancePage([
        Deposit(id: 'd3', no: 'FDR-0003', kind: 'এফডিআর', institution: 'অগ্রণী ব্যাংক', principal: 50000),
      ]);
    }
    return const FinancePage([
      Deposit(id: 'd1', no: 'FDR-0001', kind: 'এফডিআর', institution: 'সোনালী ব্যাংক', principal: 250000, maturesOn: '2026-11-02', daysToMaturity: 20),
      Deposit(id: 'd2', no: 'FDR-0002', kind: 'এফডিআর', institution: 'জনতা ব্যাংক', principal: 100000, maturesOn: '2026-10-01', daysToMaturity: -12),
    ], nextPage: 2);
  }

  @override
  Future<Deposit> deposit(String id) async => const Deposit(
        id: 'd1',
        no: 'FDR-0001',
        kind: 'ডিপিএস',
        institution: 'সোনালী ব্যাংক',
        principal: 250000,
        maturesOn: '2026-11-02',
        daysToMaturity: 20,
        instalmentAmount: 5000,
        instalmentDay: 10,
        movements: [Movement(date: '2026-10-10', kind: 'instalment', amount: 5000)],
      );

  @override
  Future<FinancePage<BankLoan>> loans({String tab = 'open', int page = 1}) async => const FinancePage([
        BankLoan(
          id: 'l1',
          no: 'BF-0001',
          kind: 'term',
          bank: 'সোনালী ব্যাংক',
          limit: 1200000,
          outstanding: 1100000,
          available: 100000,
          next: Instalment(dueOn: '2026-09-15', amount: 106618.55, state: 'overdue'),
        ),
      ]);

  @override
  Future<BankLoan> loan(String id) async => const BankLoan(
        id: 'l1',
        no: 'BF-0001',
        kind: 'term',
        bank: 'সোনালী ব্যাংক',
        limit: 1200000,
        outstanding: 1100000,
        available: 100000,
        instalments: 12,
        instalmentsPaid: 1,
        instalmentsLeft: 11,
        overdue: 1,
        next: Instalment(dueOn: '2026-09-15', amount: 106618.55, state: 'overdue'),
        schedule: [
          Instalment(month: 2, dueOn: '2026-09-15', amount: 106618.55, principal: 95000, interest: 11618.55, state: 'overdue'),
          Instalment(month: 3, dueOn: '2026-10-15', amount: 106618.55, principal: 96000, interest: 10618.55, state: 'upcoming'),
        ],
      );

  @override
  Future<FinancePage<Rent>> rents({String side = 'pay', String tab = 'running', int page = 1}) async {
    asked.add('rents:$side:$tab');
    return FinancePage([
      side == 'let'
          ? const Rent(id: 't1', side: 'let', no: 'TEN-1', party: 'করিম ট্রেডার্স', place: 'দোকান ২', monthlyRent: 8000, outstanding: 16000)
          : const Rent(
              id: 'r1', side: 'pay', no: 'RENT-1', party: 'রহমান সাহেব', place: 'গুদাম', monthlyRent: 12000,
              nextDueOn: '2026-11-05', overdueCount: 2, overdueAmount: 24000),
    ]);
  }

  @override
  Future<Rent> rent(String side, String id) async => const Rent(
        id: 'r1',
        side: 'pay',
        no: 'RENT-1',
        party: 'রহমান সাহেব',
        monthlyRent: 12000,
        rentDay: 5,
        overdueCount: 2,
        overdueAmount: 24000,
        overdueMonths: [RentMonth('2026-08-01', 12000), RentMonth('2026-09-01', 12000)],
      );

  @override
  Future<HandLoanBook> handLoans() async => const HandLoanBook(
        owedToUs: 5000,
        weOwe: 2000,
        rows: [
          HandLoan(id: 'h1', name: 'করিম', balance: 5000, dueOn: '2026-12-01', repayment: 'lump'),
          HandLoan(id: 'h2', name: 'রহিম', balance: -2000),
        ],
      );

  @override
  Future<HandLoan> handLoan(String id) async => const HandLoan(
        id: 'h1',
        name: 'করিম',
        balance: 5000,
        movements: [Movement(date: '2026-10-08', kind: 'out', amount: 5000)],
      );
}

Future<_FakeFinance> _pump(WidgetTester tester, Widget Function(_FakeFinance api) screen, {_FakeFinance? api}) async {
  final fake = api ?? _FakeFinance();
  await tester.pumpWidget(MaterialApp(home: screen(fake)));
  await tester.pumpAndSettle();
  return fake;
}

void main() {
  testWidgets('deposits stand by maturity in plain words and the tabs ask the server', (tester) async {
    final api = await _pump(tester, (a) => DepositListScreen(api: a));

    expect(find.text('FDR-0001 · এফডিআর'), findsOneWidget);
    expect(find.text('আর 20 দিন'), findsOneWidget);
    expect(find.text('12 দিন পেরিয়েছে'), findsOneWidget);
    expect(find.text(Money.taka(250000)), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('books-more')));
    await tester.pumpAndSettle();
    expect(api.asked, contains('deposits:active:2'));
    expect(find.text('FDR-0003 · এফডিআর'), findsOneWidget);
    expect(find.byKey(const ValueKey('books-more')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('tab-closed')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'deposits:closed:1');
    expect(find.text('এই ট্যাবে কোনো জমা নেই'), findsOneWidget);
  });

  testWidgets('a deposit opens with its instalment day and its movements', (tester) async {
    await _pump(tester, (a) => DepositListScreen(api: a));
    await tester.tap(find.byKey(const ValueKey('deposit-d1')));
    await tester.pumpAndSettle();

    expect(find.text('${Money.taka(5000)} · প্রতি মাসের 10 তারিখ'), findsOneWidget);
    expect(find.text('কিস্তি'), findsOneWidget);
  });

  testWidgets('a failed load says so in Bengali and pulling down asks again', (tester) async {
    final api = _FakeFinance()..fail = true;
    await _pump(tester, (a) => DepositListScreen(api: a), api: api);

    expect(find.text('আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'), findsOneWidget);
    api.fail = false;
    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(find.text('FDR-0001 · এফডিআর'), findsOneWidget);
  });

  testWidgets('a loan shows what is owed and the next instalment first', (tester) async {
    await _pump(tester, (a) => LoanListScreen(api: a));
    expect(find.textContaining('পরের কিস্তি 15/09/2026'), findsOneWidget);
    expect(find.textContaining('দিন পেরিয়েছে'), findsOneWidget);

    await tester.tap(find.text('সোনালী ব্যাংক · মেয়াদি ঋণ'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('loan-next')), findsOneWidget);
    expect(find.text('1 দেওয়া · 11 বাকি · মোট 12'), findsOneWidget);
    expect(find.text('3 নম্বর কিস্তি · 15/10/2026'), findsOneWidget);
  });

  testWidgets('rent we pay says the months owed; rent we let says what the tenant owes', (tester) async {
    final api = await _pump(tester, (a) => RentListScreen(api: a));
    expect(find.text('2 মাসের ভাড়া বাকি · ${Money.taka(24000)}'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('tab-let')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'rents:let:running');
    expect(find.text('ভাড়াটের কাছে পাওনা ${Money.taka(16000)}'), findsOneWidget);
  });

  testWidgets('a rent paper lists each month still owed', (tester) async {
    await _pump(tester, (a) => RentScreen(side: 'pay', id: 'r1', api: a));
    expect(find.byKey(const ValueKey('rent-due')), findsOneWidget);
    expect(find.text('আগস্ট 2026'), findsOneWidget);
    expect(find.text('সেপ্টেম্বর 2026'), findsOneWidget);
    expect(find.text('প্রতি মাসের 5 তারিখ'), findsOneWidget);
  });

  testWidgets('hand loans say who owes whom, with both totals on top', (tester) async {
    await _pump(tester, (a) => HandLoanListScreen(api: a));
    expect(find.byKey(const ValueKey('hand-to-us')), findsOneWidget);
    expect(find.byKey(const ValueKey('hand-we-owe')), findsOneWidget);
    expect(find.text('তিনি আমাদের দেবেন'), findsOneWidget);
    expect(find.text('আমরা তাঁকে দেব'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('hand-h1')));
    await tester.pumpAndSettle();
    expect(find.text('টাকা দেওয়া'), findsOneWidget);
  });

  test('days are said in words', () {
    expect(daysWords(0), 'আজ');
    expect(daysWords(3), 'আর 3 দিন');
    expect(daysWords(-2), '2 দিন পেরিয়েছে');
    expect(handLoanSide(0), 'মিটে গেছে');
  });
}
