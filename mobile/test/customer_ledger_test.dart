import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/features/customers/customer_ledger_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ফোনে দোকানের খাতা, সহজ করে — মালিক, ১২ অক্টোবর ২০২৬: উপরে কত বাকি, নিচে মাস ধরে "মাল নিয়েছেন / টাকা দিয়েছেন",
/// ডেবিট-ক্রেডিট শব্দ নেই; আগের পাতা চাইলে তবেই।
class _FakeLedger implements CustomerLedgerApi {
  _FakeLedger(this.due);

  final double due;
  final List<int> asked = [];

  @override
  Future<CustomerLedger> ledger(String customerId, {int page = 1}) async {
    asked.add(page);
    if (page == 2) {
      return const CustomerLedger(name: 'রহিম স্টোর', due: 1500, rows: [
        CustomerLedgerRow(date: '2026-09-28', no: 'S-0031', debit: 1000, credit: 0, balance: 1000),
      ]);
    }
    return CustomerLedger(
      name: 'রহিম স্টোর',
      code: 'C-0007',
      due: due,
      rows: const [
        CustomerLedgerRow(date: '2026-10-06', no: 'RCV-0002', debit: 0, credit: 500, balance: 1500),
        CustomerLedgerRow(date: '2026-10-02', no: 'S-0041', narration: 'চাল ১০ বস্তা', debit: 1000, credit: 0, balance: 2000),
      ],
      nextPage: 2,
    );
  }
}

Future<_FakeLedger> _open(WidgetTester tester, double due) async {
  final api = _FakeLedger(due);
  await tester.pumpWidget(MaterialApp(home: CustomerLedgerScreen(key: UniqueKey(), customerId: 'x', api: api)));
  await tester.pumpAndSettle();
  return api;
}

void main() {
  testWidgets('the due sits on top in plain words and each row says what happened', (tester) async {
    await _open(tester, 1500);

    expect(find.text('রহিম স্টোর'), findsOneWidget);
    expect(find.text('বাকি আছে'), findsOneWidget);
    expect(find.text(Money.taka(1500)), findsWidgets);
    expect(find.text('অক্টোবর ২০২৬'), findsOneWidget);
    expect(find.text('মাল নিয়েছেন'), findsOneWidget);
    expect(find.text('টাকা দিয়েছেন'), findsOneWidget);
    expect(find.textContaining('ডেবিট'), findsNothing);
    expect(find.textContaining('ক্রেডিট'), findsNothing);
  });

  testWidgets('money paid ahead reads as a deposit, a settled shop as settled', (tester) async {
    await _open(tester, -300);
    expect(find.text('আগাম জমা আছে'), findsOneWidget);

    await _open(tester, 0);
    expect(find.text('হিসাব মিলে আছে'), findsOneWidget);
  });

  testWidgets('older rows come only when asked, under their own month', (tester) async {
    final api = await _open(tester, 1500);
    expect(api.asked, [1]);

    await tester.scrollUntilVisible(find.byKey(const ValueKey('ledger-more')), 200);
    await tester.tap(find.byKey(const ValueKey('ledger-more')));
    await tester.pumpAndSettle();

    expect(api.asked, [1, 2]);
    expect(find.text('সেপ্টেম্বর ২০২৬'), findsOneWidget);
    expect(find.byKey(const ValueKey('ledger-more')), findsNothing);
  });

  test('the balance after a row is said in words', () {
    expect(dueWords(0), 'মিলে গেছে');
    expect(dueWords(250), 'বাকি ${Money.taka(250)}');
    expect(dueWords(-250), 'জমা ${Money.taka(250)}');
  });
}
