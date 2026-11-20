import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/orders/deposit_request_api.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/customers/deposit_request_screen.dart';
import 'package:abos_mobile/features/home/look_hero.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/profile/look_screen.dart';

import '../support/bar_fonts.dart';

/// ⭐ অ্যাপ 0.4.58-এর ছবি — মালিকের নমুনার পাশে মিলিয়ে দেখার জন্য (Liquid Glass, Material 3; হোম, জমার খবর, চেহারা)।
/// ⓘ ABOS-এর ছবি আগের টেস্টগুলোর (০.৪.৫৭) — সেগুলো একই থাকে।
class _SignedIn extends AuthController {
  _SignedIn() {
    state = const AuthState.signedIn(AuthUser(id: 'u1', name: 'শুভ', email: 's@t.local', roles: ['SR'], permissions: []));
  }
}

class _Api implements DepositRequestApi {
  @override
  Future<List<BankChoice>> banks() async => const [BankChoice(1, 'ইসলামী ব্যাংক · …4417')];
  @override
  Future<List<DepositRequestRow>> forCustomer(String customerId) async => const [
        DepositRequestRow(date: '2026-11-12', amount: 50000, method: 'bank', status: 'accepted', hasSlip: true, depositor: 'মালিক নিজে'),
      ];
  @override
  Future<List<OpenBill>> openBills(String customerId) async => const [OpenBill(id: 'b1', no: 'INV-0412', due: 38200)];
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
  }) async =>
      throw UnimplementedError();
}

const _today = TodayRecord({
  'sales': {'amount': '284500', 'count': 12, 'label': 'আজকের নিট বিক্রি'},
  'collections': {'amount': '124500', 'count': 12},
  'dues': {'amount': '4120000', 'shops': 41},
  'approvals': {'pending': 3},
  'money': {'amount': '1845300', 'cash': '345300', 'bank': '1500000'},
  'target': {'target': '3650000', 'achieved': '2847000'},
});

void main() {
  setUpAll(loadBarFonts);
  late AppLooks saved;
  setUp(() {
    saved = AppLooks.instance;
    AppLooks.instance = AppLooks(read: (_) async => null, write: (_, __) async {});
    AppLooks.instance.motion.value = false; // ⓘ ছবিতে ছোপ স্থির
  });
  tearDown(() => AppLooks.instance = saved);

  ThemeData themeOf(AppLook look) => withBarFonts(switch (look) {
        AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
        AppLook.expressive => AppTheme.expressive(),
        AppLook.abos => AppTheme.light(),
      });

  Future<void> shoot(WidgetTester tester, AppLook look, Widget page, String name, {BarTab selected = BarTab.home}) async {
    AppLooks.instance.look.value = look;
    tester.view.physicalSize = const Size(412, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final bar = RoleBottomBar(
      tabs: const [BarTab.home, BarTab.deposit, BarTab.orders, BarTab.modules, BarTab.me],
      selected: selected,
      badges: const {},
      onSelect: (_) {},
      onSearch: () {},
      onQuick: () {},
    );
    final body = Scaffold(body: page, bottomNavigationBar: bar);
    await tester.pumpWidget(ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => _SignedIn())],
      child: RepaintBoundary(
        key: const ValueKey('shot'),
        child: MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: themeOf(look),
          locale: AppLocale.locale,
          supportedLocales: AppLocale.supported,
          localizationsDelegates: AppLocale.delegates,
          home: look == AppLook.glass ? GlassBackdrop(child: body) : body,
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/app_0458/$name.png'));
  }

  for (final look in [AppLook.glass, AppLook.expressive]) {
    testWidgets('home hero — ${look.name}', (tester) async {
      await shoot(
          tester,
          look,
          SafeArea(child: ListView(padding: const EdgeInsets.all(14), children: const [LookHero(today: _today, name: 'শুভ')])),
          'home_${look.name}');
    });

    testWidgets('deposit report — ${look.name}', (tester) async {
      await shoot(tester, look, DepositRequestScreen(customerId: 'c1', api: _Api()), 'deposit_${look.name}',
          selected: BarTab.deposit);
    });
  }

  // ⭐ কাচের তালিকা — সার্ভার যা পাঠায় কেবল সেই সারি; কিছুই না এলে তালিকাই নেই; MFS থাকলে "নগদ · ব্যাংক" লাইন নেই
  testWidgets('glass list shows only the figures the server sent', (tester) async {
    Future<void> pump(TodayRecord t) async {
      AppLooks.instance.look.value = AppLook.glass;
      await tester.pumpWidget(MaterialApp(
        locale: AppLocale.locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        home: Scaffold(body: SingleChildScrollView(child: LookHero(today: t, name: 'শুভ'))),
      ));
      await tester.pump();
    }

    await pump(_today);
    for (final k in ['approvals', 'collections', 'dues', 'money']) {
      expect(find.byKey(ValueKey('look-row-$k')), findsOneWidget, reason: k);
    }
    expect(find.textContaining('3,45,300'), findsOneWidget);

    await pump(const TodayRecord({'sales': {'amount': '1000'}}));
    expect(find.byKey(const ValueKey('look-hero-glass-list')), findsNothing);

    await pump(const TodayRecord({
      'collections': {'amount': '500'},
      'money': {'amount': '900', 'cash': '300', 'bank': '500', 'mfs': '100'},
    }));
    expect(find.byKey(const ValueKey('look-row-collections')), findsOneWidget);
    expect(find.byKey(const ValueKey('look-row-approvals')), findsNothing);
    expect(find.byKey(const ValueKey('look-row-dues')), findsNothing);
    expect(find.byKey(const ValueKey('look-row-money')), findsOneWidget);
    expect(find.textContaining('300'), findsNothing);
  });

  testWidgets('deposit report from the shortcut — abos', (tester) async {
    await shoot(tester, AppLook.abos, DepositRequestScreen(api: _Api(), customers: () => const []), 'deposit_abos');
  });

  for (final level in [0, 40, 100]) {
    testWidgets('Profile → চেহারা — glass at $level', (tester) async {
      await AppLooks.instance.chooseBlur(level);
      await shoot(tester, AppLook.glass, const LookScreen(), 'look_screen_glass_$level');
    });
  }
}
