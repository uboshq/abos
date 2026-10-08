import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/once_key.dart';
import 'package:abos_mobile/core/orders/deposit_request_api.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/features/customers/deposit_request_screen.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// জমার বিজ্ঞপ্তি — ব্যাংকে জমায় স্লিপ ছাড়া পাঠানো যায় না; পাঠানো মানে "পাঠানো", জমা নয়।
class _FakeApi implements DepositRequestApi {
  _FakeApi({this.bills = const []});

  final sent = <Map<String, Object?>>[];

  /// খোলা বিল; `null` মানে পুরনো সার্ভার — দরজাটাই নেই
  final List<OpenBill>? bills;

  @override
  Future<List<OpenBill>> openBills(String customerId) async {
    if (bills == null) throw StateError('404');
    return bills!;
  }

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
    List<BillShare> bills = const [],
  }) async {
    sent.add({'amount': amount, 'slip': slipPath, 'method': method, 'key': OnceKey.current,
      'bills': [for (final b in bills) '${b.invoiceId}=${b.amount}']});
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
    // ⓘ a6-এর আকার: সার্ভারের নাম আগে; verifying নিজের নামেও; কে পাঠালেন ঐচ্ছিক
    expect(DepositRequestRow.fromJson({'status': 'accepted', 'status_label': 'গৃহীত হয়েছে'}).statusLabel, 'গৃহীত হয়েছে');
    expect(DepositRequestRow.fromJson({'status': 'verifying'}).statusLabel, 'যাচাই চলছে');
    expect(DepositRequestRow.fromJson({'status': 'pending', 'submitted_by_name': 'কাউসার'}).submittedBy, 'কাউসার');
    expect(DepositRequestRow.fromJson({'status': 'pending', 'submitted_by_name': ' '}).submittedBy, isNull);
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
    expect(api.sent.single['key'], isNotNull, reason: '⛔ চাবি ছাড়া পাঠাল — দুবার চাপলে দুটো বিজ্ঞপ্তি (অডিট ফোন ⚠️১২)');
    await tester.scrollUntilVisible(find.text('পাঠানো'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('পাঠানো'), findsOneWidget);
    // ⓘ বার্তাটা পাতার মাথায় — ListView নিচের দিকে গেলে উপরেরটা গাছেই থাকে না, তাই আগে উপরে ফেরা
    await tester.drag(find.byType(Scrollable).first, const Offset(0, 3000));
    await tester.pumpAndSettle();
    expect(find.textContaining('ততক্ষণ বকেয়া কমবে না'), findsOneWidget);
  });

  test('the bills the server names read, and an advice row carries the bills it was sent against', () {
    final bill = OpenBill.fromJson({'id': 'u1', 'no': 'INV-1', 'date': '2026-10-01', 'total': '900.00', 'due': '600.00'});
    expect([bill.id, bill.no, bill.due], ['u1', 'INV-1', 600]);
    final row = DepositRequestRow.fromJson({'status': 'pending', 'bills': [
      {'id': 'u1', 'no': 'INV-1', 'amount': '600.00'},
    ]});
    expect(row.bills, [('INV-1', 600.0)]);
    expect(DepositRequestRow.fromJson({'status': 'pending'}).bills, isEmpty, reason: 'পুরনো সার্ভারে বিল নেই');
  });

  group('কোন বিলের বিপরীতে (টাকার পরিকল্পনা ২)', () {
    Future<_FakeApi> open(WidgetTester tester, {List<OpenBill>? bills}) async {
      final api = _FakeApi(bills: bills);
      tester.view.physicalSize = const Size(800, 3000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(home: DepositRequestScreen(customerId: 'c1', api: api)));
      await tester.pumpAndSettle();
      await tester.tap(find.text('নগদ'));
      await tester.pumpAndSettle();
      return api;
    }

    Future<void> send(WidgetTester tester, String amount, Map<String, String> shares) async {
      await tester.enterText(find.byType(TextField).first, amount);
      for (final e in shares.entries) {
        await tester.enterText(find.byKey(ValueKey('bill-share-${e.key}')), e.value);
      }
      await tester.tap(find.text('পাঠান'));
      await tester.pumpAndSettle();
    }

    const bills = [OpenBill(id: 'b1', no: 'INV-1', due: 600), OpenBill(id: 'b2', no: 'INV-2', due: 400)];

    testWidgets('each open bill is one line with its due; the shares picked go with the advice', (tester) async {
      final api = await open(tester, bills: bills);
      expect(find.text('INV-1 · বকেয়া ${Money.taka(600)}'), findsOneWidget);
      expect(find.text('INV-2 · বকেয়া ${Money.taka(400)}'), findsOneWidget);

      await send(tester, '1000', {'b1': '600', 'b2': '300'});
      expect(api.sent.single['bills'], ['b1=600', 'b2=300']);
    });

    testWidgets('more than a bill owes, or more than the deposit, is stopped on the phone', (tester) async {
      final api = await open(tester, bills: bills);
      await send(tester, '1000', {'b1': '700'});
      expect(api.sent, isEmpty);
      expect(find.textContaining('INV-1-এর বকেয়া'), findsOneWidget);

      await send(tester, '500', {'b1': '400', 'b2': '200'});
      expect(api.sent, isEmpty);
      expect(find.textContaining('বিলের ভাগ জমার চেয়ে বেশি'), findsOneWidget);
    });

    testWidgets('no bill picked sends none; an old server without the door shows no bill lines and still sends', (tester) async {
      final api = await open(tester, bills: null);
      expect(find.text('কোন বিলের বিপরীতে (ঐচ্ছিক)'), findsNothing);
      await send(tester, '250', {});
      expect(api.sent.single['bills'], isEmpty);
    });
  });

  test('the real API names each bill field by hand: bills[i][invoice] and bills[i][amount]', () async {
    FakeSecureStorage.install();
    final real = ApiClient.dio.httpClientAdapter;
    addTearDown(() => ApiClient.dio.httpClientAdapter = real);
    final server = _Form();
    ApiClient.dio.httpClientAdapter = server;

    await const ServerDepositRequestApi().send(customerId: 'c1', date: DateTime(2026, 10, 7), amount: '900', method: 'cash',
        bills: const [BillShare('u1', '600'), BillShare('u2', '300')]);
    expect(server.fields, containsAll(['bills[0][invoice]=u1', 'bills[0][amount]=600', 'bills[1][invoice]=u2', 'bills[1][amount]=300']));
  });
}

class _Form implements HttpClientAdapter {
  final List<String> fields = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    final data = options.data;
    if (data is FormData) fields.addAll([for (final f in data.fields) '${f.key}=${f.value}']);
    return ResponseBody.fromString(jsonEncode({'id': 'x', 'status': 'pending', 'amount': '900.00'}), 201,
        headers: {Headers.contentTypeHeader: [Headers.jsonContentType]});
  }

  @override
  void close({bool force = false}) {}
}
