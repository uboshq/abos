import 'package:flutter/material.dart';
import 'dart:ui' show Tristate;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/navigation/colour_tab_icon.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/web_inside/web_inside.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/features/home/module_pages.dart';

import 'bar_harness.dart';

/// ⭐ নিচের বারের আচরণ — বারের নকশা ৩, অংশ ৪ আর ৭ (মালিকের "হ্যাঁ", ২০ অক্টোবর ২০২৬); প্রতিটা নিয়মের একটা দাবি।
void main() {
  Finder tab(String name) => find.byKey(ValueKey('nav-$name'));
  bool lit(WidgetTester tester, String name) =>
      tester.getSemantics(tab(name)).flagsCollection.isSelected == Tristate.isTrue;

  Future<GoRouter> pump(WidgetTester tester, String role,
      {String initial = '/home',
      NavBadges badges = const NavBadges(),
      bool mayCollect = false,
      Size size = const Size(360, 800),
      double textScale = 1,
      Set<String> loading = const {}}) async {
    await setScreen(tester, size);
    final router = barRouter(initial: initial, loading: loading);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(
        barApp(router, role: role, badges: badges, mayCollect: mayCollect, textScale: textScale));
    await tester.pumpAndSettle();
    return router;
  }

  // ⓘ ১৩ নভেম্বর ২০২৬ — মালিকের সিদ্ধান্ত "ছবি নিচের বারে": ভূমিকার চার ঘর + শেষে "আমি" (নিজের ছবি) = পাঁচ, Material 3-এর সীমা
  // ⭐ ১৪ নভেম্বর ২০২৬ (মালিকের নমুনা ৩): "সব মডিউল" সব ভূমিকায় মাঝখানে; ফোনের বারে কোনো লেখা নেই — নাম TalkBack-এ আর
  //   লম্বা চাপে ([Tooltip]); প্রতিটা ঘরে রঙিন আইকন ([ColourTabIcon]), "আমি"-তে নিজের বড় ছবি
  group('four role tabs and "আমি" last, modules in the middle, colour icons without words', () {
    for (final (role, names, labels) in [
      ('owner', ['home', 'approvals', 'modules', 'reports', 'me'], ['হোম', 'অনুমোদন', 'সব মডিউল', 'রিপোর্ট', 'আমি']),
      ('sr', ['home', 'orders', 'modules', 'collections', 'me'], ['হোম', 'অর্ডার', 'সব মডিউল', 'আদায়', 'আমি']),
      ('depot', ['home', 'challans', 'modules', 'stock', 'me'], ['হোম', 'চালান', 'সব মডিউল', 'মজুদ', 'আমি']),
      ('accounts', ['home', 'approvals', 'modules', 'vouchers', 'me'], ['হোম', 'অনুমোদন', 'সব মডিউল', 'ভাউচার', 'আমি']),
    ]) {
      testWidgets('$role: ${labels.join(' · ')}', (tester) async {
        await pump(tester, role);
        final xs = <double>[];
        for (var i = 0; i < names.length; i++) {
          expect(tab(names[i]), findsOneWidget, reason: names[i]);
          xs.add(tester.getCenter(tab(names[i])).dx);
          expect(find.descendant(of: tab(names[i]), matching: find.text(labels[i])), findsNothing,
              reason: '⛔ ${names[i]}: ফোনের বারে লেখা — মালিকের নমুনায় কোনো ঘরে লেখা নেই');
          expect(tester.getSemantics(tab(names[i])).label, contains(labels[i]),
              reason: '⛔ ${names[i]}: TalkBack নাম পড়ে না — লেখা তুলে দিলে চোখে কম দেখেন এমন মানুষ হারিয়ে যান');
          expect(find.descendant(of: tab(names[i]), matching: find.byWidgetPredicate((w) => w is Tooltip && w.message == labels[i])),
              findsOneWidget, reason: '⛔ ${names[i]}: লম্বা চাপে নাম ভেসে ওঠে না');
          // ⓘ "আমি"-র আইকন মানুষের নিজের ছবি (না থাকলে নামের প্রথম অক্ষর), রঙিন আইকন নয়
          expect(find.descendant(of: tab(names[i]), matching: find.byType(ColourTabIcon)), names[i] == 'me' ? findsNothing : findsOneWidget,
              reason: '⛔ ${names[i]}: রঙিন আইকন নেই');
        }
        expect(xs, orderedEquals([...xs]..sort()), reason: '⛔ ঘরের ক্রম ${labels.join(' · ')} নয়');
        expect(tester.getSize(find.descendant(of: tab('me'), matching: find.byKey(const ValueKey('nav-me-face')))).height,
            greaterThanOrEqualTo(48), reason: '⛔ নিজের ছবি বড় নয় — নমুনায় ৫৬');
        // ⓘ বাছা না থাকলে ছবির কিনারায় কোনো দাগ নয় — উপরের বারের সাদা কিনারা অন্ধকার থিমে বাছা ঘরের রিংয়ের মতো দেখাত
        final disc = tester.widget<Container>(find.descendant(of: tab('me'), matching: find.byKey(const ValueKey('avatar-disc'))));
        expect((disc.decoration! as BoxDecoration).border, isNull, reason: '⛔ বাছা না থাকা ছবির চারপাশে দাগ — দেখে মনে হয় "আমি" বাছা');
        expect(find.descendant(of: tab('me'), matching: find.byKey(const ValueKey('nav-me-face'))), findsOneWidget);
        expect(find.descendant(of: find.byKey(const ValueKey('nav-bar')), matching: find.byType(InkWell)),
            findsNWidgets(5));
      });
    }

    testWidgets('notifications are not on the bar, yet their page still opens and draws the bar; "আমি" is the last tab',
        (tester) async {
      final router = await pump(tester, 'owner');
      expect(tab('notifications'), findsNothing);
      expect(find.text('বিজ্ঞপ্তি'), findsNothing);
      expect(tab('me'), findsOneWidget);
      expect(tester.getCenter(tab('me')).dx, greaterThan(tester.getCenter(tab('modules')).dx),
          reason: '"আমি" sits last, after all modules');

      await tester.tap(tab('me'));
      await tester.pumpAndSettle();
      expect(find.text('HOME me'), findsOneWidget, reason: '"আমি" opens the home\'s own আমি page');
      expect(lit(tester, 'me'), isTrue);
      expect(lit(tester, 'home'), isFalse);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('HOME home'), findsOneWidget, reason: 'back from আমি goes home first');

      router.push('/home/notifications');
      await tester.pumpAndSettle();
      expect(find.text('NOTIFICATIONS'), findsOneWidget);
      expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
      expect(lit(tester, 'home'), isTrue, reason: 'opened from home, stays under home');
    });
  });

  group('touch targets and TalkBack', () {
    testWidgets('every tab is at least 48×48dp, on the smallest phone too', (tester) async {
      await pump(tester, 'owner', size: const Size(320, 640));
      for (final name in ['home', 'approvals', 'reports', 'modules', 'me']) {
        final s = tester.getSize(tab(name));
        expect(s.width, greaterThanOrEqualTo(48), reason: name);
        expect(s.height, greaterThanOrEqualTo(48), reason: name);
      }
    });

    testWidgets('TalkBack reads each tab in Bengali, the count with it, as a selectable button', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, 'owner', badges: const NavBadges(pending: 5));
      expect(tester.getSemantics(tab('approvals')),
          matchesSemantics(label: 'অনুমোদন, ৫টা অপেক্ষায়', isButton: true, hasTapAction: true,
              hasSelectedState: true, isInMutuallyExclusiveGroup: true));
      expect(tester.getSemantics(tab('home')),
          matchesSemantics(label: 'হোম', isButton: true, hasTapAction: true, hasSelectedState: true,
              isSelected: true, isInMutuallyExclusiveGroup: true));
      expect(tester.getSemantics(tab('reports')).label, 'রিপোর্ট');
      expect(tester.getSemantics(tab('me')).label, 'আমি');
      // one sentence per tab: the name and the red count are not read again as loose pieces
      for (final name in ['home', 'approvals', 'reports', 'modules', 'me']) {
        expect(tester.getSemantics(tab(name)).childrenCount, 0, reason: name);
      }
      expect(find.bySemanticsLabel(RegExp('অনুমোদন')), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp(r'^5$', multiLine: true)), findsNothing);
      handle.dispose();
    });

    testWidgets('the badge shows 99+ above 99, and only a dot when the count is unknown', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, 'accounts', badges: const NavBadges(pending: 250));
      expect(find.descendant(of: tab('approvals'), matching: find.text('99+')), findsOneWidget);
      expect(tester.getSemantics(tab('approvals')).label, 'অনুমোদন, ৯৯টার বেশি অপেক্ষায়');

      await pump(tester, 'accounts', badges: const NavBadges(pending: 7));
      expect(find.descendant(of: tab('approvals'), matching: find.text('7')), findsOneWidget);

      await pump(tester, 'accounts', badges: const NavBadges(pending: 25, pendingMore: true));
      expect(find.byKey(const ValueKey('nav-approvals-dot')), findsOneWidget);
      expect(find.descendant(of: tab('approvals'), matching: find.text('25')), findsNothing);
      expect(tester.getSemantics(tab('approvals')).label, 'অনুমোদন, নতুন অপেক্ষায়');

      await pump(tester, 'accounts', badges: const NavBadges(pending: 0));
      expect(find.byKey(const ValueKey('nav-approvals-badge')), findsNothing);
      expect(find.byKey(const ValueKey('nav-approvals-dot')), findsNothing);
      handle.dispose();
    });
  });

  testWidgets('200% text (real fonts): nothing overflows on a 320dp phone, and the bar stays a bar of icons', (tester) async {
    await tester.runAsync(loadRealFonts);
    for (final role in rolePermissions.keys) {
      await setScreen(tester, const Size(320, 640));
      await tester.pumpWidget(const SizedBox());
      await tester.pumpWidget(barApp(barRouter(), role: role, textScale: 2,
          badges: const NavBadges(pending: 120), themed: realFontTheme));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: role);
      // ⭐ ১৪ নভেম্বর ২০২৬ থেকে বারে লেখা নেই — বড় অক্ষরেও বারটা আইকনের বার, পর্দা খায় না; ছবি গোল থাকে, কাটা পড়ে না
      final badgeTexts = find.descendant(of: find.byKey(const ValueKey('nav-bar')), matching: find.byType(Text)).evaluate()
          .map((e) => (e.widget as Text).data ?? '').where((s) => int.tryParse(s.replaceAll('+', '')) == null && s.length > 1).toList();
      expect(badgeTexts, isEmpty, reason: '⛔ $role: বারে ঘরের নাম লেখা — নমুনায় কোনো ঘরে লেখা নেই');
      final h = tester.getSize(find.byKey(const ValueKey('nav-bar'))).height;
      expect(h, inInclusiveRange(72, 96), reason: '⛔ $role: বারের উচ্চতা $h — বড় অক্ষরে বার পর্দা খাচ্ছে, বা ৭২-এর কম');
    }
  });

  group('back: each tab its own history; a non-home tab goes home first, then the app closes', () {
    testWidgets('back on a non-home tab goes to Home, then exits', (tester) async {
      final calls = recordPlatform(tester);
      await pump(tester, 'sr');
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      expect(find.text('ORDERS'), findsOneWidget);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('HOME home'), findsOneWidget);
      expect(lit(tester, 'home'), isTrue);
      expect(calls.where((c) => c.method == 'SystemNavigator.pop'), isEmpty);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(calls.where((c) => c.method == 'SystemNavigator.pop'), hasLength(1));
    });

    testWidgets('all modules: back goes to Home, not out of the app', (tester) async {
      final calls = recordPlatform(tester);
      await pump(tester, 'depot');
      await tester.tap(tab('modules'));
      await tester.pumpAndSettle();
      expect(find.text('HOME modules'), findsOneWidget);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('HOME home'), findsOneWidget);
      expect(calls.where((c) => c.method == 'SystemNavigator.pop'), isEmpty);
    });

    testWidgets('each tab keeps its own page history', (tester) async {
      final router = await pump(tester, 'sr');
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      router.push('/home/paper/order/o1');
      await tester.pumpAndSettle();
      expect(find.text('PAPER'), findsOneWidget);
      expect(lit(tester, 'orders'), isTrue);

      await tester.tap(tab('collections'));
      await tester.pumpAndSettle();
      expect(find.text('COLLECTIONS'), findsOneWidget);
      router.push('/home/customers');
      await tester.pumpAndSettle();
      expect(lit(tester, 'collections'), isTrue, reason: 'a page opened inside a tab stays in that tab');

      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      expect(find.text('PAPER'), findsOneWidget, reason: 'the orders tab comes back where it was left');
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('ORDERS'), findsOneWidget);

      await tester.tap(tab('collections'));
      await tester.pumpAndSettle();
      expect(find.text('CUSTOMERS'), findsOneWidget);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('COLLECTIONS'), findsOneWidget);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('HOME home'), findsOneWidget);
    });
  });

  group('tapping the current tab again', () {
    testWidgets('inside the tab: back to its first page', (tester) async {
      final router = await pump(tester, 'sr');
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      router.push('/home/paper/order/o1');
      await tester.pumpAndSettle();
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      expect(find.text('PAPER'), findsNothing);
      expect(find.text('ORDERS'), findsOneWidget);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('HOME home'), findsOneWidget);
    });

    testWidgets('on the first page: the list scrolls back to the top', (tester) async {
      await pump(tester, 'sr');
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      await tester.drag(find.byKey(const ValueKey('list-ORDERS')), const Offset(0, -1500));
      await tester.pumpAndSettle();
      expect(find.text('ORDERS row 0'), findsNothing);
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      expect(find.text('ORDERS row 0'), findsOneWidget);
    });

    testWidgets('on the home too', (tester) async {
      await pump(tester, 'owner');
      await tester.drag(find.byKey(const ValueKey('list-HOME home')), const Offset(0, -1500));
      await tester.pumpAndSettle();
      expect(find.text('HOME home row 0'), findsNothing);
      await tester.tap(tab('home'));
      await tester.pumpAndSettle();
      expect(find.text('HOME home row 0'), findsOneWidget);
    });

    testWidgets('a module page inside all modules: back to all modules', (tester) async {
      await pump(tester, 'owner');
      await tester.tap(tab('modules'));
      await tester.pumpAndSettle();
      final container = ProviderScope.containerOf(tester.element(find.byType(StubHome)));
      container.read(homePageProvider.notifier).state = const HomePage.module('sales');
      await tester.pumpAndSettle();
      expect(find.text('HOME module'), findsOneWidget);
      expect(lit(tester, 'modules'), isTrue);
      await tester.tap(tab('modules'));
      await tester.pumpAndSettle();
      expect(find.text('HOME modules'), findsOneWidget);
    });
  });

  testWidgets('a light haptic on a tab change, none on a re-tap', (tester) async {
    final calls = recordPlatform(tester);
    await pump(tester, 'depot');
    await tester.tap(tab('stock'));
    await tester.pumpAndSettle();
    final haptics = calls.where((c) => c.method == 'HapticFeedback.vibrate').toList();
    expect(haptics, hasLength(1));
    expect(haptics.single.arguments, 'HapticFeedbackType.lightImpact');
    await tester.tap(tab('stock'));
    await tester.pumpAndSettle();
    expect(calls.where((c) => c.method == 'HapticFeedback.vibrate'), hasLength(1));
  });

  group('opening from a notification deep link', () {
    testWidgets('an approval link lands on the approval tab; back goes home, then out', (tester) async {
      final calls = recordPlatform(tester);
      await pump(tester, 'owner', initial: '/home/${WebPaths.navPathFor('/approvals/12')}');
      expect(find.text('WEB'), findsOneWidget);
      expect(lit(tester, 'approvals'), isTrue);
      expect(lit(tester, 'home'), isFalse);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('HOME home'), findsOneWidget);
      expect(lit(tester, 'home'), isTrue);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(calls.where((c) => c.method == 'SystemNavigator.pop'), hasLength(1));
    });

    testWidgets('a paper link lands on its tab (an SR\'s order)', (tester) async {
      await pump(tester, 'sr', initial: '/home/paper/order/o9');
      expect(find.text('PAPER'), findsOneWidget);
      expect(lit(tester, 'orders'), isTrue);
    });

    testWidgets('a link to a tab this role has not keeps Home lit', (tester) async {
      await pump(tester, 'depot', initial: '/home/${WebPaths.navPathFor('/approvals/12')}');
      expect(lit(tester, 'home'), isTrue);
    });
  });

  testWidgets('the keyboard hides the bottom bar; closing it brings it back', (tester) async {
    await pump(tester, 'sr');
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
    tester.view.viewInsets = const FakeViewPadding(bottom: 300);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('nav-bar')), findsNothing);
    tester.view.resetViewInsets();
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
  });

  group('wide windows', () {
    testWidgets('600dp: a rail on the left, no bottom bar', (tester) async {
      await pump(tester, 'owner', size: const Size(700, 1000));
      expect(find.byKey(const ValueKey('nav-rail')), findsOneWidget);
      expect(find.byKey(const ValueKey('nav-bar')), findsNothing);
      expect(tester.getTopLeft(find.byKey(const ValueKey('nav-rail'))).dx, 0);
      expect(tab('approvals'), findsOneWidget);
    });

    testWidgets('a phone on its side: the rail', (tester) async {
      await pump(tester, 'sr', size: const Size(780, 360));
      expect(find.byKey(const ValueKey('nav-rail')), findsOneWidget);
      expect(find.byKey(const ValueKey('nav-bar')), findsNothing);
    });

    testWidgets('840dp: the side menu stays open, names beside the icons', (tester) async {
      await pump(tester, 'accounts', size: const Size(1024, 768));
      expect(find.byKey(const ValueKey('nav-drawer')), findsOneWidget);
      expect(find.byKey(const ValueKey('nav-rail')), findsNothing);
      expect(tester.getSize(find.byKey(const ValueKey('nav-drawer'))).width, greaterThanOrEqualTo(240));
      final icon = tester.getCenter(find.descendant(of: tab('vouchers'), matching: find.byType(ColourTabIcon)));
      final label = tester.getCenter(find.descendant(of: tab('vouchers'), matching: find.text('ভাউচার')));
      expect(label.dx, greaterThan(icon.dx));
      expect((label.dy - icon.dy).abs(), lessThan(8));
    });

    testWidgets('the rail changes tabs and keeps 48dp targets', (tester) async {
      await pump(tester, 'depot', size: const Size(700, 1000));
      final s = tester.getSize(tab('stock'));
      expect(s.width, greaterThanOrEqualTo(48));
      expect(s.height, greaterThanOrEqualTo(48));
      await tester.tap(tab('stock'));
      await tester.pumpAndSettle();
      expect(find.text('STOCK'), findsOneWidget);
    });
  });

  group('＋ নতুন only where something can be created, only with the key', () {
    testWidgets('the SR\'s orders: ＋ নতুন অর্ডার opens a new order', (tester) async {
      await pump(tester, 'sr');
      await tester.tap(tab('orders'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('frame-fab')), findsOneWidget);
      expect(find.text('নতুন অর্ডার'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('frame-fab')));
      await tester.pumpAndSettle();
      expect(find.text('NEW ORDER'), findsOneWidget);
    });

    testWidgets('orders without the create key: no button', (tester) async {
      await setScreen(tester, const Size(360, 800));
      await tester.pumpWidget(barApp(barRouter(initial: '/home/orders'),
          role: 'sr', permissions: const ['sales.order.view', 'sales.collection.view']));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('frame-fab')), findsNothing);
    });

    testWidgets('the owner\'s home: ＋ নতুন lists what may be created', (tester) async {
      await pump(tester, 'owner', mayCollect: true);
      expect(find.byKey(const ValueKey('frame-fab')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('frame-fab')));
      await tester.pumpAndSettle();
      expect(find.text('নতুন খরচ'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('fab-choice-/home/vouchers/expense')));
      await tester.pumpAndSettle();
      expect(find.text('VOUCHER KIND'), findsOneWidget);
    });

    testWidgets('no button where nothing is created, or for a role that creates nothing', (tester) async {
      final router = await pump(tester, 'owner');
      for (final path in ['/home/approvals', '/home/reports', '/home/challans', '/home/stock', '/home/customers']) {
        router.go(path);
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('frame-fab')), findsNothing, reason: path);
      }
      await tester.tap(tab('modules'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('frame-fab')), findsNothing, reason: 'all modules');
      await pump(tester, 'depot');
      expect(find.byKey(const ValueKey('frame-fab')), findsNothing, reason: 'depot home');
    });
  });

  testWidgets('a slow network: the bar and its tabs come first, the page shows the grey skeleton', (tester) async {
    await pump(tester, 'sr', initial: '/home/orders', loading: {'orders'});
    expect(find.byType(LoadingSkeleton), findsOneWidget);
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
    for (final name in ['home', 'orders', 'collections', 'modules', 'me']) {
      expect(tab(name), findsOneWidget);
    }
    expect(lit(tester, 'orders'), isTrue);
  });

  testWidgets('a slow network on the all-modules tab: the grey skeleton, not a spinning wheel', (tester) async {
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: AllModulesPage(
                modules: const [], counts: const NavCounts(), onOpen: (_) {}, onRefresh: () async {}, loading: true))));
    await tester.pump();
    expect(find.byType(LoadingSkeleton), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });
}
