import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/orders/deposit_request_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/core/web_inside/web_inside.dart';
import 'package:abos_mobile/features/customers/deposit_request_screen.dart';
import 'package:abos_mobile/features/home/home_shell.dart';
import 'package:abos_mobile/features/scan/slip_scanner_screen.dart';
import 'package:abos_mobile/features/web_inside/web_inside_screen.dart';

/// ⭐ অ্যাপ 0.4.58 — জমার খবর (দোকান বাছা, কে জমা দিলেন, ক্যামেরা আর গ্যালারি, স্ক্যানার, মতো-দেখতে-স্লিপ), ≡-এর দ্রুত কাজ
/// আর মডিউলের ভিতরে অ্যান্ড্রয়েডের "পেছনে" (মালিক, ১৬ ও ২০ নভেম্বর ২০২৬)
class _Api implements DepositRequestApi {
  _Api({this.lookalikeOf, this.history = const []});

  final String? lookalikeOf;
  final List<DepositRequestRow> history;
  final sent = <Map<String, Object?>>[];
  final askedFor = <String>[];

  @override
  Future<List<BankChoice>> banks() async => const [BankChoice(1, 'ইসলামী ব্যাংক · …4417')];

  @override
  Future<List<DepositRequestRow>> forCustomer(String customerId) async {
    askedFor.add(customerId);
    return history;
  }

  @override
  Future<List<OpenBill>> openBills(String customerId) async => const [];

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
    String? depositor,
  }) async {
    sent.add({'customer': customerId, 'amount': amount, 'slip': slipPath, 'depositor': depositor, 'method': method});
    return DepositRequestRow(
        date: '2026-11-19', amount: double.parse(amount), method: method, status: 'pending', hasSlip: slipPath != null,
        id: 'new', depositor: depositor, lookalikeOf: lookalikeOf);
  }
}

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

void main() {
  test('the server\'s depositor and lookalike_of are read; old servers send neither', () {
    final row = DepositRequestRow.fromJson(
        {'id': 'd1', 'status': 'pending', 'depositor': 'রফিক (ভাই)', 'lookalike_of': 'd0', 'amount': '50000.00'});
    expect([row.id, row.depositor, row.lookalikeOf], ['d1', 'রফিক (ভাই)', 'd0']);
    final old = DepositRequestRow.fromJson({'status': 'pending', 'depositor': ' '});
    expect([old.id, old.depositor, old.lookalikeOf], [null, null, null]);
  });

  Future<void> big(WidgetTester tester) async {
    tester.view.physicalSize = const Size(800, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
  }

  testWidgets('from the shortcut: the shop is picked on the page, the depositor and a gallery slip go with it', (tester) async {
    await big(tester);
    final api = _Api();
    await tester.pumpWidget(MaterialApp(
      home: DepositRequestScreen(
        api: api,
        customers: () => const [CustomerRecord({'id': 'c9', 'nameBn': 'মিতালী স্টোর'})],
        galleryPicker: () async => '/tmp/whatsapp-slip.jpg',
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('টাকা জমার খবর'), findsWidgets);

    // দোকান না বেছে পাঠালে — বলে, পাঠায় না
    await tester.enterText(find.byType(TextField).first, '50000');
    await tester.tap(find.text('পাঠান'));
    await tester.pumpAndSettle();
    expect(api.sent, isEmpty);
    expect(find.text('আগে দোকান বেছে নিন।'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('deposit-shop')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('deposit-shop-c9')));
    await tester.pumpAndSettle();
    expect(find.text('মিতালী স্টোর'), findsOneWidget);
    expect(api.askedFor, ['c9'], reason: 'বাছা দোকানের আগের খবর আনা');

    await tester.enterText(find.byKey(const ValueKey('deposit-depositor')), 'রফিক (ডিলারের ভাই)');
    await tester.tap(find.byKey(const ValueKey('deposit-slip-gallery')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('deposit-slip-taken')), findsOneWidget);
    await tester.tap(find.text('পাঠান'));
    await tester.pumpAndSettle();

    expect(api.sent.single, {
      'customer': 'c9',
      'amount': '50000',
      'slip': '/tmp/whatsapp-slip.jpg',
      'depositor': 'রফিক (ডিলারের ভাই)',
      'method': 'bank',
    });
    expect(find.byKey(const ValueKey('deposit-sent')), findsOneWidget);
    expect(find.textContaining('পাঠানো হয়েছে — ৳50,000'), findsOneWidget, reason: '"জমা হয়েছে" নয়, "পাঠানো"');
    expect(find.byKey(const ValueKey('deposit-lookalike')), findsNothing, reason: 'সার্ভার কিছু বলেনি');
  });

  testWidgets('the server\'s lookalike warning is shown after sending, naming the earlier report — work is not stopped', (tester) async {
    await big(tester);
    final api = _Api(lookalikeOf: 'd0', history: const [
      DepositRequestRow(id: 'd0', date: '2026-11-12', amount: 50000, method: 'bank', status: 'accepted', hasSlip: true),
    ]);
    await tester.pumpWidget(MaterialApp(
      home: DepositRequestScreen(customerId: 'c1', api: api, picker: () async => '/tmp/slip.jpg'),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '50000');
    await tester.tap(find.byKey(const ValueKey('deposit-slip-camera')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('পাঠান'));
    await tester.pumpAndSettle();

    expect(api.sent, hasLength(1));
    expect(find.byKey(const ValueKey('deposit-lookalike')), findsOneWidget);
    expect(find.textContaining('এই স্লিপটা আগের একটার মতো দেখতে'), findsOneWidget);
    expect(find.textContaining('12-11-2026-এর জমার খবর (${Money.taka(50000)})'), findsOneWidget);
  });

  testWidgets('the slip goes through the scanner; "আবার তুলুন" takes the photo again', (tester) async {
    await big(tester);
    final api = _Api();
    var shots = 0;
    final scanned = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: DepositRequestScreen(
        customerId: 'c1',
        api: api,
        picker: () async => '/tmp/shot-${++shots}.jpg',
        scanner: (context, path) async {
          scanned.add(path);
          return scanned.length == 1 ? const SlipScanOutcome.retake() : const SlipScanOutcome.done('/tmp/clean.jpg');
        },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '1200');
    await tester.tap(find.byKey(const ValueKey('deposit-slip-camera')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('পাঠান'));
    await tester.pumpAndSettle();
    expect(scanned, ['/tmp/shot-1.jpg', '/tmp/shot-2.jpg']);
    expect(api.sent.single['slip'], '/tmp/clean.jpg', reason: 'স্ক্যান করা ছোট ছবিটাই যায়');
  });

  group('the Android back button inside a module', () {
    testWidgets('a web module page closes on back when the web has no page behind — it used to loop forever', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
                builder: (_) => WebInsideScreen(
                  webPath: '/accounts/chart',
                  title: 'খাতা',
                  deviceId: () async => 'phone-1',
                  askKey: (path, {code}) async => const WebKeyIssued('https://abos.example/accounts/chart'),
                  webBuilder: (context, url, headers) => const Text('opened'),
                ),
              )),
              child: const Text('open'),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(find.text('opened'), findsOneWidget);

      await tester.binding.handlePopRoute();
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(find.text('opened'), findsNothing, reason: '⛔ "পেছনে" চাপলে পাতা বন্ধ হলো না');
      expect(find.text('open'), findsOneWidget);

      // ✕ — একইভাবে বন্ধ
      await tester.tap(find.text('open'));
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      await tester.tap(find.byKey(const ValueKey('web-inside-close')));
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(find.text('opened'), findsNothing, reason: '⛔ ✕ চাপলে পাতা বন্ধ হলো না');
    });
  });

  // ⓘ আইটেম ১০ (২১ নভেম্বর ২০২৬): ≡ এখন পুরো মেনুর পাতা খোলে/বন্ধ করে; দ্রুত কাজ উপরের ＋-এ আর মেনুর পাতার "শর্টকাট"-এ
  group('≡ = the menu page, ＋ = quick actions', () {
    MenuItem item(String path, String label) => MenuItem(key: path, label: label, icon: Icons.circle, routeName: path);

    testWidgets('≡ opens the menu page with this person\'s shortcuts; ＋ opens the quick actions of this person\'s own menu, in order, not the module list', (tester) async {
      final opened = <String>[];
      await tester.pumpWidget(ProviderScope(
        overrides: [
          authStateProvider.overrideWith((ref) => _SignedIn(const AuthUser(
              id: 'u1', name: 'করিম', email: 'k@t.local', roles: ['SR'], permissions: ['sales.order.view', 'sales.order.create']))),
        ],
        child: MaterialApp(
          home: HomeShell(
            loadHome: (_) async => HomeMenu(items: [
              item('customers', 'গ্রাহক'),
              item('new-order', 'নতুন অর্ডার'),
              item('deposit-report', 'টাকা জমার খবর'),
              item('orders', 'অর্ডারের অবস্থা'),
            ]),
            fetchToday: () async => const TodayRecord({}),
            lastKnownToday: () => null,
            readCachedOrg: () async => null,
            cacheOrg: (_) async {},
            checkUpdate: () async => UpdateStatus.fine,
            fetchNoticeBar: () async => const [],
            fetchPending: () async => throw StateError('not an approver'),
            syncNow: () async {},
            prefs: MemoryNavPrefsStore(),
            openPath: (path) async => opened.add(path),
            findCustomers: (_) => const [],
            customerName: (_) => null,
          ),
        ),
      ));
      await tester.pumpAndSettle();

      // ≡ — the menu page, its shortcuts the same quick actions, in the same order; ≡ again closes it
      await tester.tap(find.byKey(const ValueKey('top-leading')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('menu-page')), findsOneWidget);
      expect(find.byKey(const ValueKey('quick-actions')), findsNothing, reason: '≡ আর দ্রুত কাজের পাতা খোলে না');
      final tiles = [
        for (final p in ['deposit-report', 'new-order', 'collections', 'approvals', 'today', 'customers'])
          if (find.byKey(ValueKey('menu-tile-$p')).evaluate().isNotEmpty) p,
      ];
      expect(tiles, ['deposit-report', 'new-order', 'customers']);
      expect(find.byKey(const ValueKey('module-sales')), findsNothing, reason: '⛔ ≡ আবার মডিউলের তালিকা খুলল');
      await tester.tap(find.byKey(const ValueKey('top-leading')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('menu-page')), findsNothing);

      // ＋ — the quick actions sheet
      expect(find.byTooltip('তৈরি করুন'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('top-create')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('quick-actions')), findsOneWidget);
      final shown = [
        for (final p in ['deposit-report', 'new-order', 'collections', 'approvals', 'today', 'customers'])
          if (find.byKey(ValueKey('quick-$p')).evaluate().isNotEmpty) p,
      ];
      expect(shown, ['deposit-report', 'new-order', 'customers'], reason: 'মেনুতে যা আছে কেবল তা-ই, জমা আগে');
      expect(find.byKey(const ValueKey('module-sales')), findsNothing, reason: '⛔ ≡ আবার মডিউলের তালিকা খুলল');

      await tester.tap(find.byKey(const ValueKey('quick-deposit-report')));
      await tester.pumpAndSettle();
      expect(opened, ['deposit-report']);
    });
  });
}
