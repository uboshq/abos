import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_symbols_icons/symbols.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/principal_commission_api.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_screen_bar.dart';
import 'package:abos_mobile/core/records/principal_commission.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';
import 'package:abos_mobile/features/books/principal_commission_screen.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ প্রিন্সিপালের কমিশনের পর্দা — মালিকের আদেশ "app e dekhate hobe" (fe-র মাধ্যমে; a4-এর নকশার নিয়ম ২; চুক্তি সংশোধন ১;
/// cb, ২০ অক্টোবর ২০২৬)। সার্ভার নকল ([FakePcApi]); সারির ঘর Today-র `principals`-এর একই নামে।

/// নমুনা — তিন প্রিন্সিপাল, এক চক্র; একজনের বেশি পাঠানো (পাওনা)
Map<String, dynamic> pcRow(String id, String name,
        {String branch = 'ময়মনসিংহ',
        required String inflow,
        required String commission,
        required String paid,
        required String balance,
        String rate = '2.5%',
        String? label}) =>
    {
      'supplierId': int.parse(id),
      'name': name,
      'branch': branch,
      'period': '26/09/2026 – 25/10/2026',
      'periodFrom': '2026-09-26',
      'periodTo': '2026-10-25',
      'periodSoFar': '26/09/2026 – আজ পর্যন্ত',
      'basisRate': 'বিক্রি · $rate',
      'inflow': inflow,
      'commission': commission,
      if (label != null) 'commissionLabel': label,
      'share': inflow,
      'paid': paid,
      'balance': balance,
    };

Map<String, dynamic> pcSample({Map<String, dynamic>? total}) => {
      // ⓘ মাস না চাইলে সার্ভার `month: null` দেয় (প্রতিটা চক্র আলাদা মাসে শেষ হতে পারে)
      'month': null,
      'rows': [
        // ⓘ নমুনার হার আর কমিশন মেলে: ১২,০৪,৩৪৬-এর ৪% = ৪৮,১৭৩.৮৪ (ছবিতে মালিক অঙ্ক মিলিয়ে দেখেন)
        pcRow('1', 'Star Line', rate: '4%', inflow: '1204346.0000', commission: '48173.8400', paid: '0.0000', balance: '1156172.1600'),
        pcRow('2', 'ACI লিমিটেড', inflow: '845210.0000', commission: '21130.2500', paid: '830000.0000', balance: '-5920.2500'),
        pcRow('3', 'Fresh', branch: 'নেত্রকোনা', inflow: '100000.0100', commission: '2500.0000', paid: '97500.0000', balance: '0.0100'),
      ],
      'total': total ??
          {
            'inflow': '2149556.0100',
            'commission': '71804.0900',
            'share': '2149556.0100',
            'paid': '927500.0000',
            'balance': '1150251.9200',
          },
      'web_url': '/suppliers/reports/principal-commission?month=2026-10',
    };

class FakePcApi implements PrincipalCommissionApi {
  FakePcApi({this.answer, this.error});

  Map<String, dynamic> Function(String? month)? answer;
  Object? error;
  final asked = <String?>[];

  @override
  Future<PrincipalCommissionPage> load({String? month}) async {
    asked.add(month);
    if (error != null) throw error!;
    return PrincipalCommissionPage.fromJson((answer ?? (_) => pcSample())(month));
  }
}

/// সার্ভারের দশমিক-লেখা → পয়সা (BigInt), দ্বিগুণ নির্ভুলতা ছাড়া
BigInt paisa(String decimal) {
  final neg = decimal.startsWith('-');
  final parts = (neg ? decimal.substring(1) : decimal).split('.');
  final frac = '${parts.length > 1 ? parts[1] : ''}0000'.substring(0, 4);
  final v = BigInt.parse(parts[0]) * BigInt.from(10000) + BigInt.parse(frac);
  return neg ? -v : v;
}

void main() {
  setUp(SyncStatus.instance.reset);

  Future<FakePcApi> pump(WidgetTester tester,
      {FakePcApi? api, Size size = const Size(412, 915), double textScale = 1, bool dark = false, bool session = true}) async {
    final fake = api ?? FakePcApi();
    setScreenSize(tester, size);
    await tester.pumpWidget(const SizedBox());
    final app = framed(PrincipalCommissionScreen(api: fake, clock: () => DateTime(2026, 10, 20)),
        dark: dark, textScale: textScale);
    await tester.pumpWidget(session ? inSession(app) : app);
    await tester.pumpAndSettle();
    await tester.pumpAndSettle();
    return fake;
  }

  String textOf(WidgetTester tester, Key key) => tester.widget<Text>(find.byKey(key)).data!;

  testWidgets('the screen total equals the row sum to the paisa, and is the server total as sent', (tester) async {
    await pump(tester);
    final sample = pcSample();
    final rows = (sample['rows'] as List).cast<Map<String, dynamic>>();
    final total = sample['total'] as Map<String, dynamic>;
    // ⓘ নমুনা নিজেই সত্যি: সারির পয়সা-যোগ = সার্ভারের মোট (জের, ইনফ্লো, কমিশন, পাঠানো)
    for (final f in ['balance', 'inflow', 'commission', 'paid']) {
      expect(rows.map((r) => paisa(r[f] as String)).reduce((a, b) => a + b), paisa(total[f] as String), reason: f);
    }
    final bar = tester.widget<TotalsBar>(find.byKey(const ValueKey('pc-totals-bar')));
    expect(bar.allSum, '1150251.9200', reason: 'সার্ভারের লেখাই, double নয়');
    expect(find.descendant(of: find.byKey(const ValueKey('pc-totals-bar')), matching: find.text('৳11,50,251.92')),
        findsOneWidget);
    expect(find.text('সর্বমোট · দেনা · 3 জন'), findsOneWidget);
    expect(find.text('৳21,49,556.01'), findsOneWidget, reason: 'মাথার কার্ডে সবার মোট এসেছে');
    expect(find.text('৳9,27,500.00'), findsOneWidget);
  });

  testWidgets('the app never re-adds: a server total that differs from the rows is shown as the server sent it',
      (tester) async {
    // ⓘ সার্ভারের নিয়মে মোট সবার — এখানে ইচ্ছা করে সারির যোগফল থেকে আলাদা, যাতে অ্যাপে যোগ করলে ধরা পড়ে
    await pump(tester,
        api: FakePcApi(
            answer: (_) => pcSample(total: {
                  'inflow': '9999999.0000', 'commission': '1.0000', 'paid': '2.0000', 'balance': '-123.4500',
                })));
    expect(find.descendant(of: find.byKey(const ValueKey('pc-totals-bar')), matching: find.text('৳123.45')),
        findsOneWidget);
    expect(find.text('সর্বমোট · পাওনা · 3 জন'), findsOneWidget, reason: 'ঋণাত্মক জের = পাওনা');
    expect(find.text('৳99,99,999.00'), findsOneWidget);
  });

  testWidgets('each row: name, muted এসেছে · কমিশন · পাঠানো, and the balance with its word, never a bare minus',
      (tester) async {
    await pump(tester, size: const Size(412, 1600));
    expect(find.text('Star Line · ময়মনসিংহ'), findsOneWidget);
    expect(textOf(tester, const ValueKey('pc-second-1')),
        'এসেছে ৳12,04,346.00 · কমিশন (বিক্রি · 4%) ৳48,173.84 · পাঠানো ৳0.00');
    expect(textOf(tester, const ValueKey('pc-balance-1')), '৳11,56,172.16');
    expect(textOf(tester, const ValueKey('pc-side-1')), 'দেনা · দিতে হবে');
    expect(textOf(tester, const ValueKey('pc-balance-2')), '৳5,920.25');
    expect(textOf(tester, const ValueKey('pc-side-2')), 'পাওনা · কোম্পানির কাছে পাব');
    expect(textOf(tester, const ValueKey('pc-balance-3')), '৳0.01', reason: 'এক পয়সাও দেনা');
    expect(textOf(tester, const ValueKey('pc-side-3')), 'দেনা · দিতে হবে');
    for (final id in ['1', '2', '3']) {
      expect(textOf(tester, ValueKey('pc-balance-$id')), isNot(contains('-')));
      expect(tester.getSize(find.byKey(ValueKey('pc-row-$id'))).height, greaterThanOrEqualTo(72));
    }
    // ⓘ চক্রের তারিখ প্রতিটা সারিতে
    expect(textOf(tester, const ValueKey('pc-cycle-1')), 'চক্র: 26/09/2026 – আজ পর্যন্ত');
    expect(find.text('Fresh · নেত্রকোনা'), findsOneWidget, reason: 'সংক্ষিপ্ত নামের পাশে শাখা');
  });

  testWidgets('the server\'s commissionLabel is shown as sent', (tester) async {
    await pump(tester,
        api: FakePcApi(
            answer: (_) => {
                  'month': '2026-10',
                  'rows': [
                    pcRow('9', 'Loss Co', inflow: '100.0000', commission: '-5.0000', paid: '0.0000', balance: '-5.0000',
                        label: 'লোকসান ৳5.00 — কেনা দামের নিচে বিক্রি'),
                  ],
                  'total': {'inflow': '100.0000', 'commission': '-5.0000', 'paid': '0.0000', 'balance': '-5.0000'},
                }));
    expect(textOf(tester, const ValueKey('pc-second-9')), contains('লোকসান ৳5.00 — কেনা দামের নিচে বিক্রি'));
  });

  testWidgets('the bar is AbosScreenBar reading "› সরবরাহকারী › প্রিন্সিপালের কমিশন"', (tester) async {
    await pump(tester);
    final bar = tester.widget<AbosScreenBar>(find.byType(AbosScreenBar));
    expect(bar.title, 'প্রিন্সিপালের কমিশন');
    expect(find.byKey(const ValueKey('screen-bar')), findsOneWidget);
    expect(tester.widget<Text>(find.byKey(const ValueKey('top-crumbs'))).data, '› সরবরাহকারী › প্রিন্সিপালের কমিশন');
  });

  testWidgets('three states: skeleton while loading, error with retry, empty with an action', (tester) async {
    final fake = FakePcApi(error: DioException(requestOptions: RequestOptions(), type: DioExceptionType.connectionError));
    setScreenSize(tester, const Size(412, 915));
    await tester.pumpWidget(framed(PrincipalCommissionScreen(api: fake, clock: () => DateTime(2026, 10, 20))));
    expect(find.byType(LoadingSkeleton), findsOneWidget);
    await tester.pumpAndSettle();
    expect(find.byType(ErrorState), findsOneWidget);
    expect(find.byKey(const ValueKey('pc-totals-bar')), findsNothing, reason: 'ভুলের উপর মোট নয়');

    fake
      ..error = null
      ..answer = (_) => {'month': '2026-10', 'rows': [], 'total': {'balance': '0.0000'}};
    await tester.tap(find.text('আবার চেষ্টা করুন'));
    await tester.pumpAndSettle();
    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.byKey(const ValueKey('pc-empty-refresh')), findsOneWidget);
    expect(find.byKey(const ValueKey('pc-totals-bar')), findsNothing);
    expect(tester.getSize(find.byKey(const ValueKey('pc-empty-refresh'))).height, greaterThanOrEqualTo(48));
  });

  testWidgets('the phone purchase switch off (403 module_off) reads like every switched-off screen', (tester) async {
    final req = RequestOptions(path: '/supplier/principal-commission');
    await pump(tester,
        api: FakePcApi(
            error: DioException(
                requestOptions: req,
                response: Response(requestOptions: req, statusCode: 403, data: {'error': 'module_off'}))));
    expect(find.text(ModuleGate.offTitle), findsWidgets);
    expect(find.text(ModuleGate.offMessage), findsOneWidget);
  });

  testWidgets('an old server (404) says so in Bengali, not "broken"', (tester) async {
    final req = RequestOptions(path: '/supplier/principal-commission');
    await pump(tester,
        api: FakePcApi(
            error: DioException(requestOptions: req, response: Response(requestOptions: req, statusCode: 404))));
    expect(find.textContaining('সার্ভারে এই পাতা এখনো নেই'), findsOneWidget);
  });

  testWidgets('month picker: empty = current cycle (no month sent); back a month sends ?month=2026-09; চলতি returns',
      (tester) async {
    final fake = await pump(tester);
    expect(fake.asked, [null]);
    expect(textOf(tester, const ValueKey('pc-month')), 'চলতি চক্র');
    expect(tester.widget<IconButton>(find.byKey(const ValueKey('pc-month-next'))).onPressed, isNull,
        reason: 'চলতি চক্রের পরে কিছু নেই');

    await tester.tap(find.byKey(const ValueKey('pc-month-prev')));
    await tester.pumpAndSettle();
    expect(fake.asked.last, '2026-09');
    expect(textOf(tester, const ValueKey('pc-month')), 'সেপ্টেম্বর 2026');

    await tester.tap(find.byKey(const ValueKey('pc-month-current')));
    await tester.pumpAndSettle();
    expect(fake.asked.last, isNull);
    for (final k in ['pc-month-prev', 'pc-month-next']) {
      expect(tester.getSize(find.byKey(ValueKey(k))).height, greaterThanOrEqualTo(48));
    }
  });

  testWidgets('pull to refresh asks the server again', (tester) async {
    final fake = await pump(tester);
    await tester.fling(find.byKey(const ValueKey('principal-commission-list')), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(fake.asked.length, 2);
  });

  testWidgets('nothing overflows at 200% text on a 360dp phone, light and dark', (tester) async {
    for (final dark in [false, true]) {
      await pump(tester, size: const Size(360, 800), textScale: 2, dark: dark);
      expect(tester.takeException(), isNull, reason: dark ? 'অন্ধকার' : 'আলো');
      // ⓘ পুরো তালিকা নেমে দেখা — প্রতিটা সারি আঁকা হয়, কোথাও উপচে পড়ে না
      for (final id in ['1', '2', '3']) {
        await tester.scrollUntilVisible(find.byKey(ValueKey('pc-row-$id')), 200,
            scrollable: find.descendant(
                of: find.byKey(const ValueKey('principal-commission-list')), matching: find.byType(Scrollable)));
        expect(tester.takeException(), isNull, reason: '$id, ${dark ? 'অন্ধকার' : 'আলো'}');
      }
      expect(tester.takeException(), isNull);
    }
  });

  testWidgets('dark mode: texts take the dark theme, not fixed light colours', (tester) async {
    await pump(tester, dark: true);
    final cs = Theme.of(tester.element(find.byKey(const ValueKey('pc-month')))).colorScheme;
    expect(Theme.of(tester.element(find.byKey(const ValueKey('pc-month')))).brightness, Brightness.dark);
    expect(tester.widget<Text>(find.byKey(const ValueKey('pc-month'))).style!.color, cs.onSurface);
    expect(tester.widget<Text>(find.byKey(const ValueKey('pc-side-1'))).style!.color, cs.onSurfaceVariant);
    expect(tester.widget<Text>(find.byKey(const ValueKey('pc-balance-1'))).style!.color, cs.error);
  });

  group('where the door hangs', () {
    test('a screenDef under সরবরাহকারী, Material Symbols icon, behind the supplier switch', () {
      final def = NavCatalog.screenDef('principal-commission')!;
      expect(def.module, 'supplier');
      expect(def.label, 'প্রিন্সিপালের কমিশন');
      expect(def.icon, Symbols.percent);
      expect(ModuleGate.moduleOfPath['principal-commission'], 'purchase', reason: 'সার্ভারের module_off ক্রয়ের সুইচ');
      expect(ModuleGate.allows({'supplier'}, 'principal-commission'), isFalse);
    });

    test('the tile comes with supplier.report only — the server\'s own key', () {
      AuthUser who(List<String> p) => AuthUser(id: 'u', name: 'x', email: 'x@x', roles: const [], permissions: p);
      List<MenuItem> tiles(List<String> p) => const MenuRepository().ordered(const [], who(p));
      expect(tiles(['supplier.report']).map((i) => i.routeName), contains('principal-commission'));
      expect(tiles(['supplier.view']).map((i) => i.routeName), isNot(contains('principal-commission')));
      final tree = NavTree.from(tiles(['supplier.report']));
      expect(tree.module('supplier')!.screens.map((r) => r.path), ['principal-commission']);
    });

    test('the router opens it at /home/principal-commission behind ModuleGateView', () {
      final source = File('lib/core/router/app_router.dart').readAsStringSync();
      expect(source, contains("path: 'principal-commission'"));
      expect(RegExp(r"ModuleGateView\(\s*path: 'principal-commission',\s*child: PrincipalCommissionScreen").hasMatch(source),
          isTrue);
    });

    test('the web report path (notices, links) opens this screen with its month; the web menu row is its twin', () {
      final w = NativeForWeb.screenFor('/suppliers/reports/principal-commission?month=2026-09')!;
      expect(w, isA<ModuleGateView>());
      final screen = (w as ModuleGateView).child as PrincipalCommissionScreen;
      expect(screen.month, '2026-09');
      expect(NativeForWeb.screenFor('/supplier/reports/principal-commission'), isA<ModuleGateView>());
      expect(NativeForWeb.twinOf('/suppliers/reports/principal-commission'), 'principal-commission');
    });

    test('the balance word follows the sign like the web card', () {
      expect(BalanceSide.of('6130.2500'), BalanceSide.pay);
      expect(BalanceSide.of('-1.0000'), BalanceSide.receive);
      expect(BalanceSide.of('0.0040'), BalanceSide.settled);
      expect(BalanceSide.pay.word, 'দেনা');
      expect(BalanceSide.receive.word, 'পাওনা');
    });
  });
}
