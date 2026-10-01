import 'package:abos_mobile/core/orders/deposit_request_api.dart';
import 'package:abos_mobile/features/customers/deposit_request_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// স্লিপসহ জমার অনুরোধ — ব্যাংকে জমায় স্লিপ ছাড়া পাঠানো যায় না; পাঠানো মানে "অপেক্ষায়", জমা নয়।
class _FakeApi implements DepositRequestApi {
  final sent = <Map<String, Object?>>[];

  @override
  Future<List<BankChoice>> banks() async => const [BankChoice(7, 'DBBL চলতি')];

  @override
  Future<List<DepositRequestRow>> forCustomer(String customerId) async => [
        for (final s in sent)
          DepositRequestRow(date: '2026-10-01', amount: double.parse(s['amount']! as String), method: 'bank', status: 'pending', hasSlip: true),
      ];

  @override
  Future<DepositRequestRow> send({
    required String customerId,
    required DateTime date,
    required String amount,
    required String method,
    int? bankAccountId,
    String? reference,
    String? note,
    String? slipPath,
  }) async {
    sent.add({'amount': amount, 'slip': slipPath, 'method': method});
    return DepositRequestRow(date: null, amount: double.parse(amount), method: method, status: 'pending', hasSlip: slipPath != null);
  }
}

void main() {
  test('the server row reads, and pending never says deposited', () {
    final row = DepositRequestRow.fromJson({
      'claimed_on': '2026-10-01', 'amount': '4820.00', 'method': 'bank', 'status': 'pending', 'has_slip': true,
    });
    expect(row.amount, 4820);
    expect(row.statusLabel, 'অপেক্ষায়');
    expect(DepositRequestRow.fromJson({'status': 'rejected'}).statusLabel, 'বাতিল');
  });

  testWidgets('a bank deposit is not sent without the slip, and is sent with it', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(
      home: DepositRequestScreen(customerId: 'c1', api: api, picker: () async => '/tmp/slip.jpg'),
    ));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '4820');
    await tester.tap(find.text('পাঠান'));
    await tester.pumpAndSettle();
    expect(api.sent, isEmpty);
    expect(find.text('ব্যাংকে জমায় স্লিপের ছবি দিতেই হবে।'), findsOneWidget);

    await tester.ensureVisible(find.text('স্লিপের ছবি তুলুন'));
    await tester.tap(find.text('স্লিপের ছবি তুলুন'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('পাঠান'));
    await tester.tap(find.text('পাঠান'));
    await tester.pumpAndSettle();

    expect(api.sent.single['slip'], '/tmp/slip.jpg');
    await tester.scrollUntilVisible(find.text('অপেক্ষায়'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('অপেক্ষায়'), findsOneWidget);
    // ⓘ বার্তাটা পাতার মাথায় — ListView নিচের দিকে গেলে উপরেরটা গাছেই থাকে না, তাই আগে উপরে ফেরা
    await tester.drag(find.byType(Scrollable).first, const Offset(0, 3000));
    await tester.pumpAndSettle();
    expect(find.textContaining('ততক্ষণ বকেয়া কমবে না'), findsOneWidget);
  });
}
