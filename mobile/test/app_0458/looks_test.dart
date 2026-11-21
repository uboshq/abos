import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart' show RenderParagraph;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/deposit_icon.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/profile/look_screen.dart';

import '../support/bar_fonts.dart';

/// মালিকের নমুনার সাত ঘর, ক্রমে
const _seven = [
  BarTab.home, BarTab.copilot, BarTab.deposit, BarTab.modules, BarTab.orders, BarTab.notices, BarTab.me,
];

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
    test('starts as Material, medium glass (40), colour moving — nothing stored', () async {
      final looks = _memory({});
      await looks.load();
      expect(looks.look.value, AppLook.expressive, reason: '⭐ শুরুতে ম্যাটেরিয়াল (মালিক, ২০ নভেম্বর ২০২৬)');
      expect(looks.blur.value, 40, reason: 'শুরুতে "মাঝারি"');
      expect(looks.motion.value, isTrue);
      expect(AppLooks.presets, [0, 20, 40, 70, 100], reason: 'স্বচ্ছ · হালকা · মাঝারি · ঘন · ঘষা কাচ');
    });

    // ⭐ ০.৪.৬১-এর প্রথম চালুতে একবার সবাই ম্যাটেরিয়ালে (ওয়েবের একই নিয়ম); তারপর নিজের বাছাই থাকে
    test('0.4.61 moves everyone to Material once; after that the person\'s own choice sticks', () async {
      for (final before in ['abos', 'glass', 'expressive']) {
        final box = <String, String>{AppLooks.lookKey: before};
        final first = _memory(box);
        await first.load();
        expect(first.look.value, AppLook.expressive, reason: 'আগে $before ছিল');
        expect(box[AppLooks.lookKey], 'expressive', reason: 'ফোনে লেখা');
        expect(box[AppLooks.defaultAppliedKey], isNotNull, reason: 'একবারের চিহ্ন');
        await first.chooseLook(AppLook.abos);
        final next = _memory(box);
        await next.load();
        expect(next.look.value, AppLook.abos, reason: '⛔ দ্বিতীয় চালুতে আবার বদলায় না — নিজের বাছাই "ক্লাসিক"');
      }
    });

    test('a choice is kept on the phone and read back on the next start', () async {
      final box = <String, String>{};
      final first = _memory(box);
      await first.load();
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
      final looks = _memory({AppLooks.lookKey: 'neon', AppLooks.blurKey: 'x', AppLooks.defaultAppliedKey: '1'});
      await looks.load();
      expect(looks.look.value, AppLook.expressive, reason: 'অচেনা মান — শুরুর রূপ, ম্যাটেরিয়াল');
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

    // ⭐ মালিকের ক্রম (২০ নভেম্বর ২০২৬ "Ok", বারের নমুনা): হোম · Copilot · জমা · সব মডিউল · অর্ডার · নোটিশ · আমি
    test('Liquid Glass and Material 3: Home · Copilot · Deposit · Modules · the role\'s own tab · Notices · Me', () {
      expect(RoleTabs.lookTabsFor(sr, copilot: true), [
        BarTab.home, BarTab.copilot, BarTab.deposit, BarTab.modules, BarTab.orders, BarTab.notices, BarTab.me,
      ]);
      expect(RoleTabs.lookTabsFor(owner, copilot: true), [
        BarTab.home, BarTab.copilot, BarTab.deposit, BarTab.modules, BarTab.approvals, BarTab.notices, BarTab.me,
      ]);
      // ⓘ কোপাইলটের চাবি নেই — Copilot নেই, বাকিদের ক্রম একই
      expect(RoleTabs.lookTabsFor(sr),
          [BarTab.home, BarTab.deposit, BarTab.modules, BarTab.orders, BarTab.notices, BarTab.me]);
      // ⓘ জমার চাবি নেই — জমা নেই
      expect(RoleTabs.lookTabsFor(['sales.order.view', 'sales.order.create', 'sales.collection.view'], copilot: true), [
        BarTab.home, BarTab.copilot, BarTab.orders, BarTab.modules, BarTab.collections, BarTab.notices, BarTab.me,
      ]);
    });

    test('"সব মডিউল" sits in the middle of the bar for the owner and the SR, with and without the deposit tab', () {
      const srNoKey = ['sales.order.view', 'sales.order.create', 'sales.collection.view'];
      const ownerNoKey = [
        'executive.view', 'approval.decide', 'sales.order.view', 'customer.view', 'accounts.voucher.create',
      ];
      for (final p in [sr, owner, srNoKey, ownerNoKey, [...ownerNoKey, 'sales.claim.submit']]) {
        final tabs = RoleTabs.tabsFor(p);
        expect(tabs.length.isOdd, isTrue, reason: '$tabs');
        expect(tabs.indexOf(BarTab.modules), tabs.length ~/ 2, reason: '⛔ সব মডিউল মাঝখানে নেই: $tabs');
        expect(RoleTabs.lookTabsFor(p, copilot: true).where((t) => t != BarTab.copilot && t != BarTab.notices), tabs,
            reason: 'ভূমিকার ঘর তিন রূপে একই; নতুন দুই রূপে কেবল Copilot আর নোটিশ বেশি');
        expect(RoleTabs.lookTabsFor(p, copilot: true).length, lessThanOrEqualTo(7));
      }
    });

    test('ABOS has the same tabs (only the drawing differs); without the deposit key the bar is exactly 0.4.57\'s', () {
      const noKey = ['sales.order.view', 'sales.order.create', 'sales.collection.view'];
      expect(RoleTabs.tabsFor(noKey), [BarTab.home, BarTab.orders, BarTab.modules, BarTab.collections, BarTab.me]);
      expect(RoleTabs.tabsFor(sr), contains(BarTab.deposit), reason: 'জমা ABOS-এর বারেও (সমন্বয়কারী, ২০ নভেম্বর ২০২৬)');
      expect(RoleTabs.tabsFor(sr), isNot(contains(BarTab.copilot)), reason: '⛔ ABOS-এর বার বদলায় না');
      expect(RoleTabs.tabsFor(sr), isNot(contains(BarTab.notices)), reason: '⛔ ABOS-এর বার বদলায় না');
      // ⓘ চার ঘরের ভূমিকা ("অন্য") — জমা যোগ হয়, কিছু বাদ যায় না
      expect(RoleTabs.tabsFor(['approval.decide', 'sales.claim.submit']),
          [BarTab.home, BarTab.deposit, BarTab.modules, BarTab.approvals, BarTab.me]);
      for (final p in [sr, owner, ['accounts.voucher.create', 'approval.decide', 'sales.claim.submit']]) {
        final tabs = RoleTabs.tabsFor(p);
        expect(tabs.length, lessThanOrEqualTo(5));
        expect(tabs, containsAll([BarTab.home, BarTab.modules, BarTab.me]), reason: 'হোম, মডিউল, আমি কখনো বাদ নয়');
      }
      expect(RoleTabs.lookTabsFor(sr, modulesOn: {'customer'}).contains(BarTab.deposit), isFalse,
          reason: 'বিক্রয়ের সুইচ বন্ধ — জমার ঘরও নেই');
    });

    Future<void> pumpBar(WidgetTester tester, AppLook look, {double width = 412, BarTab selected = BarTab.deposit}) async {
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
            tabs: look == AppLook.abos ? const [BarTab.home, BarTab.deposit, BarTab.modules, BarTab.orders, BarTab.me] : _seven,
            selected: selected,
            badges: {BarTab.notices: RoleTabs.badgeFor(20)},
            onSelect: (_) {},
          ),
        ),
      )));
      await tester.pumpAndSettle();
    }

    /// সাত ঘর বাঁ থেকে ডানে, যেভাবে পর্দায় বসেছে
    List<BarTab> drawnOrder(WidgetTester tester) {
      final at = <BarTab, double>{
        for (final t in _seven) t: tester.getCenter(find.byKey(ValueKey('nav-${t.name}'))).dx,
      };
      return [..._seven]..sort((a, b) => at[a]!.compareTo(at[b]!));
    }

    void iconsOnly(WidgetTester tester, String bar) {
      // ⓘ নাম লেখা নেই — কেবল লম্বা চাপের Tooltip আর TalkBack
      for (final t in _seven) {
        final label = RoleTabs.specs[t]!.label;
        expect(find.descendant(of: find.byKey(ValueKey(bar)), matching: find.text(label)), findsNothing, reason: '$t-এর নাম লেখা');
        expect(find.byTooltip(label), findsOneWidget, reason: '$t — লম্বা চাপে নাম');
      }
    }

    for (final width in [412.0, 320.0]) {
      testWidgets('Liquid Glass at ${width.toInt()}dp: one glass capsule, seven icons in order, no labels, no search ball, nothing overflows',
          (tester) async {
        await pumpBar(tester, AppLook.glass, width: width);
        expect(tester.takeException(), isNull);
        expect(find.byKey(const ValueKey('nav-bar-glass')), findsOneWidget);
        expect(find.byKey(const ValueKey('nav-search-glass')), findsNothing, reason: '⛔ নিচে খোঁজার বল নেই — খোঁজা উপরে');
        expect(find.byKey(const ValueKey('nav-quick-fab')), findsNothing);
        expect(find.byKey(const ValueKey('nav-bar')), findsNothing, reason: 'ABOS-এর বার নয়');
        expect(drawnOrder(tester), _seven);
        iconsOnly(tester, 'nav-bar-glass');
        expect(find.byKey(const ValueKey('nav-notices-badge')), findsOneWidget, reason: 'নোটিশে না-পড়ার গোনা');
        expect(find.text('20'), findsOneWidget);
        expect(find.byType(BackdropFilter), findsWidgets, reason: 'মাঝারি (40) কাচে ঝাপসা আছে');
        final bar = tester.getRect(find.byKey(const ValueKey('nav-bar-glass')));
        expect(bar.left, greaterThanOrEqualTo(0));
        expect(bar.right, lessThanOrEqualTo(width));
      });

      testWidgets('Material 3 at ${width.toInt()}dp: one dark toolbar, seven icons in order, no labels, no ＋ FAB, nothing overflows',
          (tester) async {
        await pumpBar(tester, AppLook.expressive, width: width);
        expect(tester.takeException(), isNull);
        expect(find.byKey(const ValueKey('nav-bar-expressive')), findsOneWidget);
        expect(find.byKey(const ValueKey('nav-quick-fab')), findsNothing, reason: '⛔ ＋ উপরের বারে, নিচে আলাদা ＋ নেই');
        expect(find.byKey(const ValueKey('nav-search-glass')), findsNothing);
        expect(drawnOrder(tester), _seven);
        iconsOnly(tester, 'nav-bar-expressive');
        expect(find.byKey(const ValueKey('nav-notices-badge')), findsOneWidget, reason: 'নোটিশে না-পড়ার গোনা');
        final bar = tester.getRect(find.byKey(const ValueKey('nav-bar-expressive')));
        expect(bar.right, lessThanOrEqualTo(width));
      });
    }

    // ⭐ বাছা ঘর Facebook-এর মতো, তিন রূপেই (মালিক, ২০ নভেম্বর ২০২৬ "ok"): পেছনে গোল বা বড়ি নেই; বাছা আইকন ভরাট আর নীল,
    // ওপরের কিনারায় সরু নীল দাগ; বাকিরা রেখার আইকন, মলিন রঙে; "আমি" বাছা হলে ছবিতে ২dp নীল রিং
    for (final look in AppLook.values) {
      for (final pick in [BarTab.home, BarTab.orders, BarTab.deposit, BarTab.me]) {
        testWidgets('${look.name}: ${pick.name} selected — filled blue icon and a thin blue line on top, no pill', (tester) async {
          await pumpBar(tester, look, selected: pick);
          expect(tester.takeException(), isNull);
          final tabs = look == AppLook.abos ? [BarTab.home, BarTab.deposit, BarTab.modules, BarTab.orders, BarTab.me] : _seven;
          for (final t in tabs) {
            expect(find.byKey(ValueKey('nav-${t.name}-line')), t == pick ? findsOneWidget : findsNothing, reason: '$t');
          }
          final line = tester.getRect(find.byKey(ValueKey('nav-${pick.name}-line')));
          expect(line.width, closeTo(28, 0.01));
          expect(line.height, closeTo(3, 0.01));
          final slot = tester.getRect(find.byKey(ValueKey('nav-${pick.name}')));
          expect((line.center.dx - slot.center.dx).abs(), lessThan(1), reason: 'দাগ আইকনের ঠিক ওপরে');
          expect(line.top, lessThan(slot.top), reason: 'দাগ বারের ওপরের কিনারায়');
          final blue = tester.widget<Container>(find.byKey(ValueKey('nav-${pick.name}-line'))).decoration as BoxDecoration;
          for (final t in [BarTab.home, BarTab.modules, BarTab.orders]) {
            final icon = tester.widget<Icon>(find.byKey(ValueKey('nav-${t.name}-icon')));
            expect(icon.fill, t == pick ? 1 : 0, reason: '$t: বাছা ভরাট, বাকিরা রেখা');
            if (t == pick) {
              expect(icon.color, blue.color, reason: 'বাছা আইকন দাগের একই নীল');
            } else {
              expect(icon.color, isNot(blue.color), reason: '$t মলিন রঙে');
            }
          }
          expect(tester.widget<DepositIcon>(find.byKey(const ValueKey('nav-deposit-icon'))).filled, pick == BarTab.deposit);
          // ⛔ পেছনে গোল বা বড়ি নেই — কোনো ঘরের ভেতরে রং-ভরা আকার নয়
          for (final t in tabs) {
            final filledShapes = find.descendant(
              of: find.byKey(ValueKey('nav-${t.name}')),
              matching: find.byWidgetPredicate((w) =>
                  (w is Container && w.decoration is BoxDecoration && (w.decoration as BoxDecoration).color != null) ||
                  (w is AnimatedContainer && w.decoration is BoxDecoration && (w.decoration as BoxDecoration).color != null)),
            );
            expect(filledShapes, findsNothing, reason: '$t');
          }
          final ring = find.descendant(of: find.byKey(const ValueKey('nav-me')), matching: find.byKey(const ValueKey('avatar-ring')));
          if (pick == BarTab.me) {
            final border = (tester.widget<DecoratedBox>(ring).decoration as BoxDecoration).border! as Border;
            expect(border.top.width, 2);
            expect(border.top.color, blue.color, reason: '"আমি"-র ছবিতে নীল রিং');
          } else {
            expect(ring, findsNothing);
          }
        });
      }
    }

    testWidgets('ABOS: no glass, no ＋, no search ball', (tester) async {
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

  // ⭐ উপরের বার (মালিক, ২০ নভেম্বর ২০২৬ "Ok", বারের নমুনা): নতুন দুই রূপে ডানে ＋ · খোঁজা · বার্তা; ঘণ্টা আর Copilot নিচে
  group('the top bar in each look', () {
    setUpAll(loadBarFonts);

    Future<List<String>> pumpTop(WidgetTester tester, AppLook look,
        {double width = 412, VoidCallback? onCreate, String company = 'ADI Corporation', bool messaging = true}) async {
      AppLooks.instance = _memory({});
      AppLooks.instance.look.value = look;
      tester.view.physicalSize = Size(width, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(
        // ⓘ আসল ফন্ট — নামের মাপ (৩৬০dp-তে পুরো নাম ধরে কি না) পরীক্ষার চৌকো ফন্টে মাপা যায় না
        theme: withBarFonts(switch (look) {
          AppLook.glass => AppTheme.glass(GlassSpec.of(40)),
          AppLook.expressive => AppTheme.expressive(),
          AppLook.abos => AppTheme.light(),
        }),
        locale: AppLocale.locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        home: Scaffold(
          appBar: AbosTopBar(
            company: company,
            companyCode: 'ADI',
            branch: 'TD শাখা',
            demo: false,
            syncStatus: SyncStatus(),
            onLeading: () {},
            onOrgTap: () {},
            onSearch: () {},
            onCopilot: () {},
            onCreate: onCreate ?? () {},
            onBell: () {},
            // ⓘ বার্তা চালু (`features.messaging`) হলে তবেই বার্তা-বোতাম
            onMessages: messaging ? () {} : null,
            unread: 20,
            avatarName: 'শুভ',
            showAvatar: false,
            onAvatar: (_) {},
          ),
          body: const SizedBox.expand(),
        ),
      ));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      final keys = ['top-create', 'top-copilot', 'top-search', 'top-messages', 'top-bell'];
      final shown = [for (final k in keys) if (find.byKey(ValueKey(k)).evaluate().isNotEmpty) k];
      shown.sort((a, b) =>
          tester.getCenter(find.byKey(ValueKey(a))).dx.compareTo(tester.getCenter(find.byKey(ValueKey(b))).dx));
      return shown;
    }

    // ⭐ নমুনা v8 (মালিকের "Ok", ২০ নভেম্বর ২০২৬), তিন রূপেই: ≡ · কোম্পানির পুরো নাম (১৫, মোটা) · শাখা গোল চিপে;
    // ডানে সাদামাটা ＋ · খোঁজা · বার্তা, কাছাকাছি, চাপার জায়গা ৪৪dp; ঘণ্টা আর Copilot নিচের বারে
    for (final look in [AppLook.expressive, AppLook.glass, AppLook.abos]) {
      for (final width in [412.0, 360.0, 320.0]) {
        testWidgets('${look.name} at ${width.toInt()}dp: ≡ · full company name · branch chip, then plain ＋ · search · messages',
            (tester) async {
          var created = 0;
          expect(await pumpTop(tester, look, width: width, onCreate: () => created++),
              ['top-create', 'top-search', 'top-messages'], reason: 'ঘণ্টা আর Copilot উপরে নেই');
          await tester.tap(find.byKey(const ValueKey('top-create')));
          expect(created, 1, reason: '＋ দ্রুত কাজ খোলে');

          final name = tester.widget<Text>(find.byKey(const ValueKey('top-company')));
          expect(name.data, 'ADI Corporation', reason: '⛔ কখনো ছোট নাম নয়');
          expect(name.style!.fontSize, 15);
          expect(name.style!.fontWeight, FontWeight.w700);
          expect(name.maxLines, 1);
          if (width >= 360) {
            // ⓘ ৩৬০dp-তে পুরো নাম ধরে — "…" নয়
            final rp = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-company')));
            expect(rp.didExceedMaxLines, isFalse, reason: '"ADI Corporation" ${width.toInt()}dp-তে পুরো ধরেনি');
          }
          final chip = tester.getRect(find.byKey(const ValueKey('top-branch-chip')));
          final nameRect = tester.getRect(find.byKey(const ValueKey('top-company')));
          expect(chip.top, greaterThan(nameRect.bottom - 0.5), reason: 'শাখা নামের নিচে');
          final deco = tester.widget<Container>(find.byKey(const ValueKey('top-branch-chip'))).decoration as BoxDecoration;
          expect(deco.borderRadius, BorderRadius.circular(999), reason: 'পুরো গোল চিপ');
          expect(deco.border, isNotNull);
          final branch = tester.widget<Text>(find.byKey(const ValueKey('top-branch')));
          expect(branch.style!.fontSize, 11);
          expect(branch.style!.fontWeight, FontWeight.w600);
          final leading = tester.getRect(find.byKey(const ValueKey('top-leading')));
          expect(leading.right, lessThanOrEqualTo(nameRect.left + 0.5), reason: '≡ বাঁয়ে, তারপর নাম');

          // ⓘ আইকনে কোনো গোল বা বাক্স নেই; চাপার জায়গা ৪৪×৪৪; একটার পরে আরেকটা, ফাঁক ছাড়াই, ডান কিনারায়
          final rects = [for (final k in ['top-create', 'top-search', 'top-messages']) tester.getRect(find.byKey(ValueKey(k)))];
          for (final r in rects) {
            expect(r.width, greaterThanOrEqualTo(44));
            expect(r.height, greaterThanOrEqualTo(44));
          }
          expect(rects[1].left - rects[0].right, lessThan(1));
          expect(rects[2].left - rects[1].right, lessThan(1));
          expect(width - rects[2].right, lessThanOrEqualTo(8), reason: 'ডান কিনারায়');
          for (final k in ['top-create', 'top-search', 'top-messages']) {
            final button = tester.widget<IconButton>(find.descendant(of: find.byKey(ValueKey(k)), matching: find.byType(IconButton)).first);
            expect(button.style?.backgroundColor, isNull, reason: '$k: পেছনে রং নেই');
          }
        });
      }

      // ⭐ বার্তা ও কল (২১ নভেম্বর ২০২৬): বার্তা চালু না থাকলে (`features.messaging` false) বোতামই নেই — "শীঘ্রই" আর নয়
      testWidgets('${look.name}: messaging off — no messages button, no "coming soon"', (tester) async {
        expect(await pumpTop(tester, look, messaging: false), ['top-create', 'top-search']);
        expect(find.byKey(const ValueKey('messages-soon')), findsNothing);
      });
    }

    testWidgets('a long company name ends in "…" — it never runs under the buttons', (tester) async {
      await pumpTop(tester, AppLook.expressive, width: 320, company: 'ADI Corporation Wholesale and Distribution Limited');
      final nameRect = tester.getRect(find.byKey(const ValueKey('top-company')));
      expect(nameRect.right, lessThanOrEqualTo(tester.getRect(find.byKey(const ValueKey('top-create'))).left + 0.5));
      expect(tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-company'))).didExceedMaxLines, isTrue);
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
      expect(find.byKey(const ValueKey('look-blur')), findsNothing, reason: 'ক্লাসিকে দাগের কাজ নেই');
      // ⭐ ক্রম আর নাম (মালিক, ২০ নভেম্বর ২০২৬ "ok"): স্মার্ট · গ্লাস · ক্লাসিক; "ABOS", "Material", "Liquid Glass" আর নাম নয়
      final ys = [
        for (final k in ['look-expressive', 'look-glass', 'look-abos']) tester.getCenter(find.byKey(ValueKey(k))).dy,
      ];
      expect(ys[0] < ys[1] && ys[1] < ys[2], isTrue, reason: '$ys');
      for (final name in ['স্মার্ট', 'গ্লাস', 'ক্লাসিক']) {
        expect(find.descendant(of: find.byType(LookScreen), matching: find.textContaining(name)), findsWidgets, reason: name);
      }
      for (final gone in ['ABOS', 'Material', 'Liquid', 'ম্যাটেরিয়াল', 'লিকুইড', 'iOS']) {
        expect(find.descendant(of: find.byType(LookScreen), matching: find.textContaining(gone)), findsNothing, reason: gone);
      }

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
