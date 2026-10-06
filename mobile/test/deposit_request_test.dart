import 'package:abos_mobile/core/orders/deposit_request_api.dart';
import 'package:abos_mobile/features/customers/deposit_request_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// জমার বিজ্ঞপ্তি — ব্যাংকে জমায় স্লিপ ছাড়া পাঠানো যায় না; পাঠানো মানে "পাঠানো", জমা নয়।
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

class _RowsApi extends _FakeApi {
  _RowsApi(this.rows);

  final List<DepositRequestRow> rows;

  @override
  Future<List<DepositRequestRow>> forCustomer(String customerId) async => rows;
}

void main() {
  test('the server row reads, and pending never says deposited', () {
    final row = DepositRequestRow.fromJson({
      'claimed_on': '2026-10-01', 'amount': '4820.00', 'method': 'bank', 'status': 'pending', 'has_slip': true,
    });
    expect(row.amount, 4820);
    expect(row.statusLabel, 'পাঠানো');
    expect(DepositRequestRow.fromJson({'status': 'accepted'}).statusLabel, 'গৃহীত');
    expect(DepositRequestRow.fromJson({'status': 'rejected'}).statusLabel, 'প্রত্যাখ্যাত');
    // ⓘ সার্ভারের নতুন অবস্থা — তার নিজের লেখা; লেখা না থাকলে "যাচাই চলছে", কখনো কাঁচা চাবি নয়
    expect(DepositRequestRow.fromJson({'status': 'verifying', 'status_label': 'যাচাই চলছে'}).statusLabel, 'যাচাই চলছে');
    expect(DepositRequestRow.fromJson({'status': 'somethingNew'}).statusLabel, 'যাচাই চলছে');
  });

  testWidgets('a rejected advice shows why, in red; the four names are on the list', (tester) async {
    final api = _RowsApi([
      const DepositRequestRow(date: '2026-10-05', amount: 100, method: 'bank', status: 'pending', hasSlip: true),
      const DepositRequestRow(date: '2026-10-05', amount: 200, method: 'bank', status: 'checking', hasSlip: true, serverLabel: 'যাচাই চলছে'),
      const DepositRequestRow(date: '2026-10-04', amount: 300, method: 'bank', status: 'accepted', hasSlip: true),
      const DepositRequestRow(date: '2026-10-03', amount: 400, method: 'bank', status: 'rejected', hasSlip: true, reason: 'স্টেটমেন্টে টাকা আসেনি'),
      const DepositRequestRow(date: '2026-10-02', amount: 500, method: 'mfs', status: 'rejected', hasSlip: false),
    ]);
    tester.view.physicalSize = const Size(800, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: DepositRequestScreen(customerId: 'c1', api: api)));
    await tester.pumpAndSettle();

    expect(find.text('জমার বিজ্ঞপ্তি'), findsOneWidget);
    for (final label in ['পাঠানো', 'যাচাই চলছে', 'গৃহীত']) {
      expect(find.text(label), findsOneWidget, reason: label);
    }
    expect(find.text('প্রত্যাখ্যাত'), findsNWidgets(2));
    expect(find.text('কারণ: স্টেটমেন্টে টাকা আসেনি'), findsOneWidget);
    expect(find.text('কারণ: জানানো হয়নি'), findsOneWidget, reason: '⛔ প্রত্যাখ্যানে কারণের জায়গা ফাঁকা');
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
    await tester.scrollUntilVisible(find.text('পাঠানো'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('পাঠানো'), findsOneWidget);
    // ⓘ বার্তাটা পাতার মাথায় — ListView নিচের দিকে গেলে উপরেরটা গাছেই থাকে না, তাই আগে উপরে ফেরা
    await tester.drag(find.byType(Scrollable).first, const Offset(0, 3000));
    await tester.pumpAndSettle();
    expect(find.textContaining('ততক্ষণ বকেয়া কমবে না'), findsOneWidget);
  });
}
