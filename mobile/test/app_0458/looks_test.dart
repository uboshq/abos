import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/profile/look_screen.dart';

class _SignedIn extends AuthController {
  _SignedIn() {
    state = const AuthState.signedIn(AuthUser(id: 'u1', name: 'শুভ', email: 's@t.local', roles: ['SR'], permissions: []));
  }
}

/// ⭐ অ্যাপ 0.4.58 — তিন রূপ আর কাচের ঝাপসা (মালিক, ২০ নভেম্বর ২০২৬ "ok")
AppLooks _memory(Map<String, String> box) =>
    AppLooks(read: (k) async => box[k], write: (k, v) async => box[k] = v);

void main() {
  late AppLooks saved;
  setUp(() => saved = AppLooks.instance);
  tearDown(() => AppLooks.instance = saved);

  group('the look store', () {
    test('starts as ABOS, medium glass (40), colour moving — nothing stored', () async {
      final looks = _memory({});
      await looks.load();
      expect(looks.look.value, AppLook.abos, reason: '⛔ শুরুতে ABOS — এখনকার রূপ');
      expect(looks.blur.value, 40, reason: 'শুরুতে "মাঝারি"');
      expect(looks.motion.value, isTrue);
      expect(AppLooks.presets, [0, 20, 40, 70, 100], reason: 'স্বচ্ছ · হালকা · মাঝারি · ঘন · ঘষা কাচ');
    });

    test('a choice is kept on the phone and read back on the next start', () async {
      final box = <String, String>{};
      final first = _memory(box);
      await first.chooseLook(AppLook.glass);
      await first.chooseBlur(70);
      await first.chooseMotion(false);
      final next = _memory(box);
      await next.load();
      expect(next.look.value, AppLook.glass);
      expect(next.blur.value, 70);
      expect(next.motion.value, isFalse);
    });

    test('a broken or unknown stored value falls back, never throws; the slider clamps 0–100', () async {
      final looks = _memory({AppLooks.lookKey: 'neon', AppLooks.blurKey: 'x'});
      await looks.load();
      expect(looks.look.value, AppLook.abos);
      expect(looks.blur.value, 40);
      await looks.chooseBlur(140);
      expect(looks.blur.value, 100);
      await looks.chooseBlur(-5);
      expect(looks.blur.value, 0);
      final throwing = AppLooks(read: (_) async => throw StateError('keystore'), write: (_, __) async => throw StateError('keystore'));
      await throwing.load();
      await throwing.chooseLook(AppLook.expressive);
      expect(throwing.look.value, AppLook.expressive, reason: 'লেখা না গেলেও এই চালুতে খাটে');
    });
  });

  group('the blur slider maps onto the glass', () {
    test('0 = mirror-clear: no blur, almost no white, only the edge light', () {
      final g = GlassSpec.of(0);
      expect(g.sigma, 0);
      expect(g.fill, closeTo(0.01, 1e-9));
      expect(g.edge, greaterThan(0.2), reason: 'কিনারের আলো থাকে — কাচ চেনা যায়');
    });

    test('100 = frosted; 40 (the default) in between; every step only grows', () {
      final frosted = GlassSpec.of(100);
      expect(frosted.sigma, 28, reason: 'ঝাপসার নমুনার blur(k × 28px)');
      expect(frosted.fill, closeTo(0.18, 1e-9));
      final medium = GlassSpec.of(40);
      expect(medium.sigma, closeTo(11.2, 1e-9));
      for (var v = 1; v <= 100; v++) {
        final a = GlassSpec.of(v - 1), b = GlassSpec.of(v);
        expect(b.sigma, greaterThan(a.sigma));
        expect(b.fill, greaterThan(a.fill));
      }
      expect(GlassSpec.of(250).sigma, 28, reason: 'সীমার বাইরে ছাঁটা');
    });
  });

  group('the bottom bar in each look', () {
    const sr = ['sales.order.view', 'sales.order.create', 'sales.collection.view', 'sales.claim.submit'];
    const owner = ['executive.view', 'approval.decide', 'sales.order.view', 'customer.view', 'sales.claim.submit'];

    test('Liquid Glass and Material 3: Home · Deposit · the role\'s own tab · Modules · Me', () {
      expect(RoleTabs.lookTabsFor(sr), [BarTab.home, BarTab.deposit, BarTab.orders, BarTab.modules, BarTab.me]);
      expect(RoleTabs.lookTabsFor(owner), [BarTab.home, BarTab.deposit, BarTab.approvals, BarTab.modules, BarTab.me]);
    });

    test('ABOS has the same tabs (only the drawing differs); without the deposit key the bar is exactly 0.4.57\'s', () {
      const noKey = ['sales.order.view', 'sales.order.create', 'sales.collection.view'];
      expect(RoleTabs.tabsFor(noKey), [BarTab.home, BarTab.orders, BarTab.modules, BarTab.collections, BarTab.me]);
      expect(RoleTabs.tabsFor(sr), RoleTabs.lookTabsFor(sr), reason: 'জমা ABOS-এর বারেও (সমন্বয়কারী, ২০ নভেম্বর ২০২৬)');
      // ⓘ চার ঘরের ভূমিকা ("অন্য") — জমা যোগ হয়, কিছু বাদ যায় না
      expect(RoleTabs.tabsFor(['approval.decide', 'sales.claim.submit']),
          [BarTab.home, BarTab.deposit, BarTab.approvals, BarTab.modules, BarTab.me]);
      for (final p in [sr, owner, ['accounts.voucher.create', 'approval.decide', 'sales.claim.submit']]) {
        final tabs = RoleTabs.tabsFor(p);
        expect(tabs.length, lessThanOrEqualTo(5));
        expect(tabs, containsAll([BarTab.home, BarTab.modules, BarTab.me]), reason: 'হোম, মডিউল, আমি কখনো বাদ নয়');
      }
      expect(RoleTabs.lookTabsFor(sr, modulesOn: {'customer'}).contains(BarTab.deposit), isFalse,
          reason: 'বিক্রয়ের সুইচ বন্ধ — জমার ঘরও নেই');
    });

    Future<void> pumpBar(WidgetTester tester, AppLook look, {double width = 412}) async {
      AppLooks.instance = _memory({});
      AppLooks.instance.look.value = look;
      tester.view.physicalSize = Size(width, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(ProviderScope(
          overrides: [authStateProvider.overrideWith((ref) => _SignedIn())],
          child: MaterialApp(
        theme: switch (look) {
          AppLook.glass => AppTheme.glass(GlassSpec.of(40)),
          AppLook.expressive => AppTheme.expressive(),
          AppLook.abos => AppTheme.light(),
        },
        home: Scaffold(
          bottomNavigationBar: RoleBottomBar(
            tabs: const [BarTab.home, BarTab.deposit, BarTab.orders, BarTab.modules, BarTab.me],
            selected: BarTab.deposit,
            badges: const {},
            onSelect: (_) {},
            onSearch: () {},
            onQuick: () {},
          ),
        ),
      )));
      await tester.pumpAndSettle();
    }

    for (final width in [412.0, 320.0]) {
      testWidgets('Liquid Glass at ${width.toInt()}dp: a floating glass capsule and the glass search ball, nothing overflows',
          (tester) async {
        await pumpBar(tester, AppLook.glass, width: width);
        expect(find.byKey(const ValueKey('nav-bar-glass')), findsOneWidget);
        expect(find.byKey(const ValueKey('nav-search-glass')), findsOneWidget);
        expect(find.byKey(const ValueKey('nav-quick-fab')), findsNothing);
        expect(find.byKey(const ValueKey('nav-bar')), findsNothing, reason: 'ABOS-এর বার নয়');
        expect(find.byType(BackdropFilter), findsWidgets, reason: 'মাঝারি (40) কাচে ঝাপসা আছে');
        expect(tester.takeException(), isNull);
      });

      testWidgets('Material 3 at ${width.toInt()}dp: the dark floating toolbar and the big square ＋, nothing overflows',
          (tester) async {
        await pumpBar(tester, AppLook.expressive, width: width);
        expect(find.byKey(const ValueKey('nav-bar-expressive')), findsOneWidget);
        expect(find.byKey(const ValueKey('nav-quick-fab')), findsOneWidget);
        expect(find.byKey(const ValueKey('nav-search-glass')), findsNothing);
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('ABOS: the same bar as 0.4.57 — no glass, no ＋, no search ball', (tester) async {
      await pumpBar(tester, AppLook.abos);
      expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
      expect(find.byKey(const ValueKey('nav-bar-glass')), findsNothing);
      expect(find.byKey(const ValueKey('nav-quick-fab')), findsNothing);
      expect(find.byKey(const ValueKey('nav-search-glass')), findsNothing);
    });

    testWidgets('glass at 0 draws no blur at all — mirror-clear', (tester) async {
      AppLooks.instance = _memory({});
      AppLooks.instance.look.value = AppLook.glass;
      await AppLooks.instance.chooseBlur(0);
      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.glass(GlassSpec.of(0)),
        home: const Scaffold(body: Center(child: GlassPanel(child: SizedBox(width: 100, height: 40)))),
      ));
      expect(find.byType(BackdropFilter), findsNothing);
      await AppLooks.instance.chooseBlur(100);
      await tester.pump();
      expect(find.byType(BackdropFilter), findsOneWidget, reason: 'দাগ টানলে সাথে সাথে বদলায়');
    });
  });

  group('Profile → চেহারা', () {
    testWidgets('three looks; the blur slider, five presets and motion only under Liquid Glass', (tester) async {
      final box = <String, String>{};
      final looks = _memory(box);
      AppLooks.instance = looks;
      tester.view.physicalSize = const Size(412, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: LookScreen(looks: looks)));
      await tester.pumpAndSettle();

      for (final k in ['look-abos', 'look-glass', 'look-expressive']) {
        expect(find.byKey(ValueKey(k)), findsOneWidget, reason: k);
      }
      expect(find.byKey(const ValueKey('look-blur')), findsNothing, reason: 'ABOS-এ দাগের কাজ নেই');

      await tester.tap(find.byKey(const ValueKey('look-glass')));
      await tester.pumpAndSettle();
      expect(looks.look.value, AppLook.glass);
      expect(box[AppLooks.lookKey], 'glass', reason: 'ফোনে লেখা');
      expect(find.byKey(const ValueKey('look-blur')), findsOneWidget);
      expect(find.text('40'), findsOneWidget, reason: 'শুরুতে মাঝারি');
      for (final v in AppLooks.presets) {
        expect(find.byKey(ValueKey('look-preset-$v')), findsOneWidget);
      }

      await tester.tap(find.byKey(const ValueKey('look-preset-70')));
      await tester.pumpAndSettle();
      expect(looks.blur.value, 70);
      expect(box[AppLooks.blurKey], '70');

      // দাগ একদম বাঁয়ে টানলে 0 — আয়নার মতো স্বচ্ছ
      final slider = find.byKey(const ValueKey('look-blur'));
      await tester.drag(slider, const Offset(-600, 0));
      await tester.pumpAndSettle();
      expect(looks.blur.value, 0);

      await tester.ensureVisible(find.byKey(const ValueKey('look-motion')));
      await tester.tap(find.byKey(const ValueKey('look-motion')));
      await tester.pumpAndSettle();
      expect(looks.motion.value, isFalse);
      expect(box[AppLooks.motionKey], 'off');

      await tester.tap(find.byKey(const ValueKey('look-expressive')));
      await tester.pumpAndSettle();
      expect(looks.look.value, AppLook.expressive);
      expect(find.byKey(const ValueKey('look-blur')), findsNothing, reason: 'Material 3-এ দাগ নেই');
    });
  });
}
