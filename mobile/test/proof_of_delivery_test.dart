import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/orders/paper_scan_api.dart';
import 'package:abos_mobile/features/deliveries/deliveries_screen.dart';
import 'package:abos_mobile/features/scan/receive_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ পৌঁছানোর প্রমাণ, ফোনে (0.4.18) — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬।
const _lines = [
  ScannedLine(line: 1, product: 'কসমস বিস্কুট', qty: 5, freeQty: 0),
  ScannedLine(line: 2, product: 'প্রিমিয়াম চা', qty: 3, freeQty: 0),
];

class _FakeScan implements PaperScanApi {
  Map<String, dynamic>? sent;

  @override
  Future<ScannedPaper> deliver(String token,
      {required String receiver,
      required String phone,
      List<ScannedLine> lines = const [],
      Map<int, double> taken = const {},
      Map<int, double> damaged = const {}}) async {
    sent = {
      'token': token,
      ...deliveryPayload(
          receiver: receiver,
          phone: phone,
          lines: lines,
          taken: taken,
          damaged: damaged)
    };
    final partial = sent!.containsKey('lines');
    return ScannedPaper.fromJson({
      'document_no': 'CH-1',
      'stage': partial ? 'partially_delivered' : 'delivered'
    });
  }

  @override
  Future<ScannedPaper> gateOut(String token) => throw UnimplementedError();

  @override
  Future<ScannedPaper> open(String token) => throw UnimplementedError();
}

class _FakeRun implements DeliveryRunApi {
  int calls = 0;
  bool done = false;

  @override
  Future<DeliveryRunPage> today({int page = 1}) async {
    calls++;
    return DeliveryRunPage(done
        ? const []
        : const [
            DeliveryRunRow(
                token: 'tok-1',
                documentNo: 'CH-0001',
                customer: 'রহিম স্টোর',
                phone: '01711-111111',
                address: 'ফুলপুর বাজার',
                vehicle: 'DM-T 11-1234',
                driver: 'করিম · 01800-000000',
                lines: _lines),
          ]);
  }
}

Future<void> _pump(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

void main() {
  group('what goes to the server', () {
    test('all full and nothing broken: only the name and the phone — delivered',
        () {
      final p = deliveryPayload(
          receiver: 'রহিম',
          phone: '01711',
          lines: _lines,
          taken: {1: 5, 2: 3},
          damaged: {1: 0, 2: 0});
      expect(p, {'receiver_name': 'রহিম', 'receiver_phone': '01711'});
    });

    test(
        'anything short or broken: every line by number, and only the broken ones under damaged',
        () {
      final p = deliveryPayload(
          receiver: 'রহিম',
          phone: '01711',
          lines: _lines,
          taken: {1: 3, 2: 3},
          damaged: {1: 1, 2: 0});
      expect(p['lines'], {'1': '3.0000', '2': '3.0000'});
      expect(p['damaged'], {'1': '1.0000'});
    });
  });

  testWidgets(
      'the hand-over form needs the phone, refuses more than was sent, and says what is short',
      (tester) async {
    ReceiveResult? got;
    await _pump(
      tester,
      Builder(
        builder: (context) => Scaffold(
          body: Center(
            child: TextButton(
              onPressed: () async => got = await Navigator.of(context)
                  .push<ReceiveResult>(MaterialPageRoute(
                      builder: (_) => const ReceiveScreen(
                          documentNo: 'CH-0001', lines: _lines))),
              child: const Text('খুলুন'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('খুলুন'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const ValueKey('receive-name')), 'রহিম');
    await tester.tap(find.byKey(const ValueKey('receive-done')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('receive-error')), findsOneWidget,
        reason: '⛔ ফোন ছাড়াই নিশ্চিত হলো');
    expect(got, isNull);

    await tester.enterText(
        find.byKey(const ValueKey('receive-phone')), '01711-000000');
    await tester.enterText(find.byKey(const ValueKey('receive-taken-1')), '4');
    await tester.enterText(
        find.byKey(const ValueKey('receive-damaged-1')), '2');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('receive-done')));
    await tester.pumpAndSettle();
    expect(got, isNull, reason: '⛔ নেওয়া + ভাঙা চালানের চেয়ে বেশি, তবু গেল');

    await tester.enterText(find.byKey(const ValueKey('receive-taken-1')), '3');
    await tester.enterText(
        find.byKey(const ValueKey('receive-damaged-1')), '1');
    await tester.pumpAndSettle();
    expect(find.text('কম: 1'), findsOneWidget, reason: 'কম নিজে গোনা হয়নি');

    await tester.tap(find.byKey(const ValueKey('receive-done')));
    await tester.pumpAndSettle();
    expect(got?.phone, '01711-000000');
    expect(got?.taken, {1: 3, 2: 3});
    expect(got?.damaged, {1: 1, 2: 0});
  });

  testWidgets(
      "today's deliveries: what is on the way, and a hand-over goes to the paper's own door",
      (tester) async {
    final run = _FakeRun();
    final scan = _FakeScan();
    await _pump(tester, DeliveriesScreen(api: run, scan: scan));

    expect(find.text('রহিম স্টোর'), findsOneWidget);
    expect(find.text('CH-0001 · 01711-111111'), findsOneWidget);
    expect(find.text('গাড়ি DM-T 11-1234 · চালক করিম · 01800-000000'),
        findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('deliver-CH-0001')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('receive-name')), 'রহিম');
    await tester.enterText(
        find.byKey(const ValueKey('receive-phone')), '01711-000000');
    await tester.enterText(
        find.byKey(const ValueKey('receive-damaged-2')), '1');
    await tester.enterText(find.byKey(const ValueKey('receive-taken-2')), '2');
    run.done = true;
    await tester.tap(find.byKey(const ValueKey('receive-done')));
    await tester.pumpAndSettle();

    expect(scan.sent?['token'], 'tok-1',
        reason: '⛔ চালানের নিজের টোকেনে যায়নি');
    expect(scan.sent?['damaged'], {'2': '1.0000'});
    expect(find.byKey(const ValueKey('deliveries-notice')), findsOneWidget);
    expect(find.textContaining('আংশিক পৌঁছেছে'), findsOneWidget);
    expect(find.text('পথে কোনো চালান নেই।'), findsOneWidget,
        reason: 'পৌঁছানোর পরে তালিকা নতুন করে আনা হয়নি');
  });

  test('the tile follows the delivery update key and the sales switch', () {
    const repository = MenuRepository();
    Set<String> keysOf(List<String> p) => repository
        .ordered(
            const [],
            AuthUser(
                id: '1',
                name: 'X',
                email: 'x@abos.test',
                roles: const [],
                permissions: p))
        .map((i) => i.key)
        .toSet();
    expect(keysOf(['sales.delivery.update']), contains('sales.deliveries'));
    expect(
        keysOf(['sales.delivery.view']), isNot(contains('sales.deliveries')));
    expect(ModuleGate.moduleOfPath['deliveries'], 'sales');
  });
}
