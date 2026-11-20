import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/records/sales_order_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/widgets/design_list_row.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/home/owner_home_cards.dart' show AmountLine, PaperRow;
import 'package:abos_mobile/features/orders/order_list_screen.dart';

import 'navigation/bar_harness.dart' show rolePermissions, loadRealFonts, realFontTheme;
import 'support/fab_screens.dart';
import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart' show seedMainData, setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ "＋ নতুন" বোতাম কখনো তথ্য ঢাকে না — স্থির অবস্থায় (কোনো স্ক্রলের আগে), এক ধাপ স্ক্রলের পরে, আর একেবারে শেষে; ৩২০,
/// ৩৬০ আর ৪১২dp ফোনে, ১০০% আর ২০০% অক্ষরে (মালিকের নিয়ম ০.৪.৪৭ থেকে; fe, ২৪ অক্টোবর ২০২৬, ০.৪.৪৮)।
///
/// <p>Material 3: বোতাম নিচের বারের ভেতরে (BottomAppBar + endContained; [ActionBottomBar]), মোটের পাশে; তালিকা বারের
/// ওপরেই শেষ। দাবি: প্রতিটা দেখা লেখা (বারের মোটসহ), প্রতিটা কার্ড আর তালিকার মোটের সারির (যেমন "মোট · 2টি দোকান") দেখা
/// অংশ বোতামের আয়তক্ষেত্রের সাথে মেলে না। ⓘ "দেখা অংশ": তালিকার জানালার বাইরে আগে-থেকে-আঁকা সারি (cache) ধরা হয় না।
///
/// <p>আসল ফন্টে (Roboto + Shonar Bangla) — ২০০% অক্ষরে টাকার চওড়া Ahem-এ মাপলে মিথ্যা হত।
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

/// এক ধাপ: প্রতিটা খাড়া তালিকা তার জানালার অর্ধেক নিচে
Future<void> _scrollOneStep(WidgetTester tester) async {
  for (final s in tester.stateList<ScrollableState>(find.byType(Scrollable))) {
    final p = s.position;
    if (p.axis != Axis.vertical || !p.hasContentDimensions) continue;
    p.jumpTo((p.pixels + p.viewportDimension / 2).clamp(p.minScrollExtent, p.maxScrollExtent));
  }
  await tester.pumpAndSettle();
}

/// সব খাড়া তালিকা একেবারে শেষে
Future<void> _scrollToEnd(WidgetTester tester) async {
  for (var round = 0; round < 3; round++) {
    for (final s in tester.stateList<ScrollableState>(find.byType(Scrollable))) {
      final p = s.position;
      if (p.axis == Axis.vertical && p.hasContentDimensions) p.jumpTo(p.maxScrollExtent);
    }
    await tester.pumpAndSettle();
  }
}

Rect _globalRect(RenderBox box) => MatrixUtils.transformRect(box.getTransformTo(null), Offset.zero & box.size);

/// [element]-এর ঘরের যে অংশ চোখে পড়ে — প্রতিটা ঘেরা তালিকার জানালায় কাটা
Rect _visible(Element element, Rect rect) {
  var r = rect;
  var scrollable = Scrollable.maybeOf(element);
  while (scrollable != null) {
    final box = scrollable.context.findRenderObject();
    if (box is RenderBox && box.hasSize) r = r.intersect(_globalRect(box));
    scrollable = Scrollable.maybeOf(scrollable.context);
  }
  return r;
}

/// বোতামের ঘরের সাথে মেলা প্রতিটা দেখা লেখা (বোতামের নিজের লেখা বাদে), কার্ড আর চাবি-দেওয়া মোটের সারি
List<String> _underFab(WidgetTester tester, Finder fab, {List<String> keyed = const []}) {
  final fabRect = tester.getRect(fab);
  final hidden = <String>[];
  final inFab = find.descendant(of: fab, matching: find.byType(RichText)).evaluate().toSet();
  void check(Element e, String what) {
    final box = e.renderObject;
    if (box is! RenderBox || !box.hasSize || !box.attached) return;
    final rect = _visible(e, _globalRect(box));
    if (rect.width <= 0 || rect.height <= 0) return;
    if (rect.overlaps(fabRect)) hidden.add('$what $rect under the button $fabRect');
  }

  for (final e in find.byType(RichText).evaluate()) {
    if (inFab.contains(e)) continue;
    check(e, '"${(e.renderObject! as RenderParagraph).text.toPlainText()}"');
  }
  // ⓘ কার্ডের নিচের দাগও তথ্য
  for (final e in find.byType(Card).evaluate()) {
    check(e, 'card ${e.widget.key}');
  }
  for (final k in keyed) {
    for (final e in find.byKey(ValueKey(k)).evaluate()) {
      check(e, k);
    }
  }
  return hidden;
}

/// ⓘ দাবি যেন ফাঁকা না হয়: তালিকার ভেতরের কয়টা লেখা চোখে পড়ে (বারের ওপরে)
int _listTextsShown(WidgetTester tester) {
  var shown = 0;
  for (final e in find.byType(RichText).evaluate()) {
    if (Scrollable.maybeOf(e) == null) continue;
    final box = e.renderObject;
    if (box is! RenderBox || !box.hasSize || !box.attached) continue;
    final r = _visible(e, _globalRect(box));
    if (r.width > 0 && r.height > 0) shown++;
  }
  return shown;
}

/// লেখা কয় লাইনে — অক্ষরের ঘরগুলো খাড়া ভাবে একে অপরের সাথে মিললে এক লাইন (৳ বাংলা ফন্টে, অঙ্ক Roboto-তে: এক লাইনেই
/// দুই উচ্চতার ঘর)
int _lineCount(RenderParagraph p, String text) =>
    _boxLines(p.getBoxesForSelection(TextSelection(baseOffset: 0, extentOffset: text.length)));

/// একটা টাকা: "৳", চিহ্ন, অঙ্ক, কমা আর দশমিক
final _taka = RegExp(r'৳\s?-?[0-9০-৯][0-9০-৯,]*(?:\.[0-9০-৯]+)?');

int _boxLines(List<TextBox> found) {
  final boxes = [...found]..sort((a, b) => a.top.compareTo(b.top));
  var lines = 0;
  var bottom = double.negativeInfinity;
  for (final b in boxes) {
    if (b.top >= bottom - 0.5) {
      lines++;
      bottom = b.bottom;
    } else if (b.bottom > bottom) {
      bottom = b.bottom;
    }
  }
  return lines;
}

/// পর্দার নিচের বারের প্রতিটা লেখা — মোটের পট্টি ('totals-bar') আর বোতামের বার ('action-bar'), দুটোই যা আছে
/// ⓘ একই লেখা দুবার থাকতে পারে (এই পাতা আর সর্বমোট একই টাকা) — তাই তালিকা, গোনাসহ
List<String> _barTexts(WidgetTester tester) => [
      for (final e in {
        for (final key in ['totals-bar', 'action-bar'])
          ...find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(RichText)).evaluate(),
      })
        (e.renderObject! as RenderParagraph).text.toPlainText(),
    ];

/// [old]-এর যে লেখা [now]-এ নেই (বা কম বার আছে)
List<String> _dropped(List<String> old, List<String> now) {
  final left = [...now];
  return [for (final s in old) if (!left.remove(s)) s];
}

/// প্রতিটা পর্দার সারির তথ্যের কাগজের নম্বর (নমুনা থেকে, আঁকা লেখা থেকে নয়)
List<String> _numbersOf(String screen) => switch (screen) {
      'SR collections' => [for (final r in collectionRows) r.no],
      'owner home' => const ['RCV-0190', 'PBL-0010', 'EXP-0044'],
      'orders' => [for (final o in SalesOrderRecord.all()) o.documentNo].whereType<String>().where((n) => n.isNotEmpty).toList(),
      _ => fabScreenNumbers[screen] ?? const [],
    };

const _longShop = 'মেসার্স ভাই ভাই এন্টারপ্রাইজ অ্যান্ড জেনারেল স্টোর · ফুলপুর বাজার · ময়মনসিংহ';

final _sr = AuthUser(id: 'u2', name: 'করিম উদ্দিন', email: 'sr@x', roles: const ['SR'], permissions: rolePermissions['sr']!);
/// জমার খবরের চাবিসহ মালিক — আদায়ের তালিকার বোতাম "টাকা জমার খবর" (২০ নভেম্বর ২০২৬)
final _depositor = AuthUser(
    id: 'u3',
    name: ownerUser.name,
    email: ownerUser.email,
    roles: ownerUser.roles,
    permissions: [...rolePermissions['owner']!, 'sales.claim.submit']);
final _owner =
    AuthUser(id: 'u1', name: ownerUser.name, email: ownerUser.email, roles: ownerUser.roles, permissions: rolePermissions['owner']!);

/// আসল রাউটারের গড়ন — প্রতিটা পর্দা ScreenFrame-এ (নিচের বারসহ)
Widget _app(String initial, {required double scale, AuthUser? user, NavigatorObserver? observer}) => ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => _SignedIn(user ?? _owner)),
        topBarContextProvider.overrideWith((ref) => sampleTopBar()),
        navBadgesProvider.overrideWith((ref) => const NavBadges(unread: 3, pending: 3)),
        mayCollectProvider.overrideWith((ref) => true),
      ],
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        theme: realFontTheme(AppTheme.light()),
        builder: (context, inner) =>
            MediaQuery(data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)), child: inner!),
        routerConfig: GoRouter(initialLocation: initial, observers: [if (observer != null) observer], routes: [
          GoRoute(
            path: '/home',
            builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: ownerHomeBody()),
            routes: [
              GoRoute(
                path: 'orders',
                builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: const OrderListScreen()),
              ),
              GoRoute(
                path: 'collections',
                builder: (context, state) => ScreenFrame(
                  location: state.matchedLocation,
                  child: MoneyInListScreen(
                      api: SampleBooks(),
                      today: DateTime(2026, 10, 20),
                      // ⓘ নমুনার দুই দোকান আর একটা লম্বা নামের — ৩৬০dp-তে নাম "…"-এ কাটে (কাটা নামের দাবি)
                      dues: () => [...sampleDues(), const ShopDue(_longShop, 5250)],
                      unsent: () => const [],
                      canReportDeposit: true,
                      reportDeposit: () => const Scaffold(body: Text('DEPOSIT REPORT SCREEN'))),
                ),
              ),
              GoRoute(path: 'new-order', builder: (context, state) => const Scaffold(body: Text('NEW ORDER SCREEN'))),
            ],
          ),
        ]),
      ),
    );

/// একটা পর্দা নিজের MaterialApp-এ
Widget _plain(Widget screen, {required double scale, NavigatorObserver? observer}) => MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: realFontTheme(AppTheme.light()),
      navigatorObservers: [if (observer != null) observer],
      builder: (context, inner) =>
          MediaQuery(data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)), child: inner!),
      home: screen,
    );

class _Pushes extends NavigatorObserver {
  final pushed = <Route<dynamic>>[];

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) => pushed.add(route);
}

/// এক পর্দার পরীক্ষার গড়ন
typedef _Case = ({
  Widget Function(double scale, NavigatorObserver? observer) app,
  String fab,
  List<String> keyed,
  Future<void> Function(WidgetTester) ready,
  Future<void> Function(WidgetTester)? opened,
});

Future<void> _none(WidgetTester tester) async {}

Map<String, _Case> _cases() => {
      // ⓘ SR-এর আদায়, "আজ" বাছা — ০.৪.৪১ নমুনার একই তালিকা; "মোট · 2টি দোকান" তালিকার ভেতরের মোট
      'SR collections': (
        app: (scale, observer) => _app('/home/collections', scale: scale, user: _sr, observer: observer),
        fab: 'money-in-deposit-report',
        keyed: const ['shop-dues-total', 'shop-dues'],
        ready: (tester) async {
          await tester.tap(find.byKey(const ValueKey('range-today')));
          await tester.pumpAndSettle();
        },
        opened: (tester) async => expect(find.text('DEPOSIT REPORT SCREEN'), findsOneWidget),
      ),
      'owner home': (
        app: (scale, observer) => _app('/home', scale: scale, observer: observer),
        fab: 'frame-fab',
        keyed: const [],
        ready: _none,
        opened: (tester) async => expect(find.byKey(const ValueKey('fab-choice-/home/new-order')), findsOneWidget),
      ),
      'orders': (
        app: (scale, observer) => _app('/home/orders', scale: scale, observer: observer),
        fab: 'frame-fab',
        keyed: const [],
        ready: _none,
        opened: (tester) async => expect(find.text('NEW ORDER SCREEN'), findsOneWidget),
      ),
      for (final MapEntry(key: name, value: s) in fabScreens().entries)
        name: (
          app: (scale, observer) => _plain(s.screen(), scale: scale, observer: observer),
          fab: s.fab,
          keyed: const [],
          ready: _none,
          opened: null,
        ),
    };

/// নিচের বারে মোটওয়ালা পর্দা — fe-র দুই শর্ত (কোনো অঙ্ক বাদ নয়; টাকা কাটা নয়) এদের ওপর
const _withTotals = ['SR collections', 'orders', 'DOs'];

/// ⭐ dd0c247e6-এ নিচের বারে যা যা লেখা ছিল — একই নমুনা, ৪১২dp, ১০০% অক্ষর। ⓘ dd0c247e6-এর lib ফিরিয়ে লিখে এই ফাইলেরই
/// "nothing dropped" পরীক্ষা চালিয়ে ছাপা থেকে নেওয়া (তখন বার = কেবল মোটের পট্টি, বোতাম পাতার ওপরে ভাসত)।
const _barAtDd0c247e6 = <String, List<String>>{
  'SR collections': ['আজ', 'এই পাতা · 3টি', '৳50,500.00', 'সর্বমোট · 3টি', '৳50,500.00'],
  'orders': ['সব তারিখ', 'এই পাতা · 1টি', '৳1,25,400.00', 'সর্বমোট · 1টি', '৳1,25,400.00'],
  'DOs': [
    'সব তারিখ',
    'এই পাতা · 10টি',
    '৳6,50,000.00',
    'প্রত্যাখ্যাত, মোটে নেই · 1টি',
    '৳37,500.00',
    'সর্বমোট · 37টি',
    '৳18,42,650.50',
  ],
};

const _widths = {320: Size(320, 640), 360: Size(360, 800), 412: Size(412, 915)};

Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
    ..addFont(Future.value(ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
  await loader.load();
}

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
    await loadRealFonts();
    await _loadRounded();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(SyncStatus.instance.reset);

  final cases = _cases();

  Future<Finder> open(WidgetTester tester, _Case c, Size size, double scale, {NavigatorObserver? observer}) async {
    setScreenSize(tester, size);
    await tester.runAsync(seedMainData);
    await tester.pumpWidget(c.app(scale, observer));
    await tester.pumpAndSettle();
    await c.ready(tester);
    final fab = find.byKey(ValueKey(c.fab));
    expect(fab, findsOneWidget, reason: 'the screen has no "+ new" button');
    return fab;
  }

  // ⭐ দাবি ১: স্থির, এক ধাপ, শেষ — বোতাম কিছু ঢাকে না
  for (final MapEntry(key: name, value: c) in cases.entries) {
    for (final MapEntry(key: width, value: size) in _widths.entries) {
      for (final scale in [1.0, 2.0]) {
        final at = '$name ${width}dp ${(scale * 100).round()}%';
        testWidgets('$at at rest: the button covers nothing', (tester) async {
          final fab = await open(tester, c, size, scale);
          expect(_underFab(tester, fab, keyed: c.keyed), isEmpty,
              reason: '⛔ at rest, before any scroll, "+ new" covers data on $at');
          // ⓘ ফাঁকা দাবি নয়: পাতা আঁকা হয়েছে, বার পাতা দখল করেনি (৩২০dp-তে ২০০% অক্ষরে তিন লাইনের মোট আর বোতামের সারি
          // মিলে পাতার অর্ধেকের একটু বেশি — তাই ৬০%)
          expect(_listTextsShown(tester), greaterThan(2), reason: 'the list shows nothing on $at');
          final bar = find.byKey(const ValueKey('action-bar'));
          if (bar.evaluate().isNotEmpty) {
            expect(tester.getSize(bar).height, lessThan(size.height * 0.6), reason: 'the bottom bar takes over the page on $at');
          }
        });
        testWidgets('$at after one scroll step: the button covers nothing', (tester) async {
          final fab = await open(tester, c, size, scale);
          await _scrollOneStep(tester);
          expect(_underFab(tester, fab, keyed: c.keyed), isEmpty, reason: '⛔ after one scroll step "+ new" covers data on $at');
        });
        testWidgets('$at at the end: the button covers nothing', (tester) async {
          final fab = await open(tester, c, size, scale);
          await _scrollToEnd(tester);
          expect(_underFab(tester, fab, keyed: c.keyed), isEmpty, reason: '⛔ at the end "+ new" covers data on $at');
        });
      }
    }

    // ⭐ ফোনে বোতাম নিচের বারের ভেতরে, পাতার ওপরে ভাসে না
    testWidgets('$name 360dp: the button sits inside the bottom bar', (tester) async {
      final fab = await open(tester, c, _widths[360]!, 1);
      expect(find.ancestor(of: fab, matching: find.byKey(const ValueKey('action-bar'))), findsOneWidget,
          reason: 'the button is not in the bottom bar');
      expect(find.ancestor(of: fab, matching: find.byType(ActionBottomBar)), findsOneWidget);
    });

    // ⭐ কেবল-আইকন হলেও নাম থাকে — ছুঁয়ে-ধরার লেখায় আর TalkBack-এ; আর চাপলে আগের কাজই
    testWidgets('$name 320dp 200%: the button keeps its name (tooltip and TalkBack when icon-only) and does the same',
        (tester) async {
      final handle = tester.ensureSemantics();
      final pushes = _Pushes();
      final fab = await open(tester, c, _widths[320]!, 2, observer: pushes);
      final label = tester.widget<BarFab>(fab).label;
      final shown = find.descendant(of: fab, matching: find.text(label)).evaluate().isNotEmpty;
      // ⓘ মোটের পাশে জায়গা কম — কেবল আইকন; মোট ছাড়া সরু বারে নাম ধরলে পুরো রূপই থাকে
      if (_withTotals.contains(name)) expect(shown, isFalse, reason: 'at 320dp and 200% beside the totals the label should fold');
      if (!shown) {
        final button = tester.widget<FloatingActionButton>(find.descendant(of: fab, matching: find.byType(FloatingActionButton)));
        expect(button.tooltip, label, reason: 'the icon-only button lost its name as a tooltip');
      }
      expect(find.descendant(of: fab, matching: find.bySemanticsLabel(label)), findsWidgets, reason: 'TalkBack does not say "$label"');
      final before = pushes.pushed.length;
      await tester.tap(fab);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(pushes.pushed.length, greaterThan(before), reason: 'tapping "$label" opened nothing');
      if (c.opened case final opened?) {
        await tester.pumpAndSettle();
        await opened(tester);
      }
      handle.dispose();
    });

    testWidgets('$name 412dp 100%: tapping the button does what it did', (tester) async {
      final pushes = _Pushes();
      final fab = await open(tester, c, _widths[412]!, 1, observer: pushes);
      final before = pushes.pushed.length;
      await tester.tap(fab);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(pushes.pushed.length, greaterThan(before));
      if (c.opened case final opened?) {
        await tester.pumpAndSettle();
        await opened(tester);
      }
    });
  }

  // ⭐ তালিকার সারির টাকাও কখনো ভাঙে না, কাটে না, পর্দার বাইরে যায় না (মালিকের নিয়ম ০.৪.৪৭ থেকে; fe, ২৪ অক্টোবর ২০২৬,
  // ০.৪.৪৮): সরু সারি অঙ্ক ছোট করে না — দুই তলা হয়, অঙ্ক নিচে ডানে, পুরো মাপে ([Amounts], amount_text.dart)।
  // ⓘ টাকা = "৳"-ওয়ালা লেখা; স্থির অবস্থায় আর শেষে, যত সারি আঁকা হয়েছে সব
  for (final MapEntry(key: name, value: c) in cases.entries) {
    for (final MapEntry(key: width, value: size) in _widths.entries) {
      for (final scale in [1.0, 2.0]) {
        final at = '$name ${width}dp ${(scale * 100).round()}%';
        testWidgets('$at: every amount in the list is whole, on one line, inside the screen', (tester) async {
          await open(tester, c, size, scale);
          final broken = <String>{};
          var amounts = 0;
          void look() {
            for (final e in find.byType(RichText).evaluate()) {
              if (Scrollable.maybeOf(e) == null) continue;
              final p = e.renderObject! as RenderParagraph;
              if (!p.hasSize || !p.attached) continue;
              final text = p.text.toPlainText();
              if (!text.contains('৳')) continue;
              final rect = _globalRect(p);
              if (p.didExceedMaxLines) broken.add('"$text" cut ("…")');
              // ⓘ প্রতিটা টাকার টুকরো ("৳" থেকে অঙ্কের শেষ) — লেখার বাকিটা (পয়েন্ট · শাখা) ফাঁকে মুড়তে পারে, টাকা নয়
              for (final m in _taka.allMatches(text)) {
                amounts++;
                final amount = m.group(0)!;
                final boxes = p.getBoxesForSelection(TextSelection(baseOffset: m.start, extentOffset: m.end));
                final lines = _boxLines(boxes);
                if (lines != 1) broken.add('"$amount" in "$text" on $lines lines');
                if (boxes.any((b) => b.left < -0.5 || b.right > p.size.width + 0.5 || b.bottom > p.size.height + 0.5)) {
                  broken.add('"$amount" in "$text" not drawn whole');
                }
                for (final b in boxes) {
                  final r = MatrixUtils.transformRect(p.getTransformTo(null), b.toRect());
                  if (r.left < -0.5 || r.right > size.width + 0.5) broken.add('"$amount" $r outside the screen');
                }
              }
              if (rect.left < -0.5 || rect.right > size.width + 0.5) broken.add('"$text" $rect outside the screen');
            }
          }

          look();
          await _scrollToEnd(tester);
          look();
          if (amounts == 0) return; // ⓘ এই পর্দার সারিতে টাকা নেই (গোনা, ছুটি, যাচাই, দায়িত্ব, লিড)
          expect(broken, isEmpty, reason: '⛔ an amount in the list breaks, is cut or leaves the screen on $at');
        });
      }
    }
  }

  // ⭐ কাগজের নম্বর কখনো কাটা পড়ে না (fe, ২৪ অক্টোবর ২০২৬, ০.৪.৪৮): কাটা যায় কেবল নাম। নম্বর ("RCV-0193", "SO-…", "DO-…",
  // "RV-…") যে লেখায় আছে সেটা "…"-এ কাটা নয়, নম্বর এক লাইনে, পুরো আঁকা, পর্দার ভেতরে। নম্বর সারির তথ্য থেকে
  // ([_numbersOf]); প্রতিটা পর্দায় তথ্যের অন্তত একটা নম্বর আঁকা থাকতে হবে।
  for (final MapEntry(key: name, value: c) in cases.entries) {
    for (final MapEntry(key: width, value: size) in _widths.entries) {
      for (final scale in [1.0, 2.0]) {
        final at = '$name ${width}dp ${(scale * 100).round()}%';
        testWidgets('$at: every paper number in the list is whole, on one line, inside the screen', (tester) async {
          await open(tester, c, size, scale);
          final numbers = _numbersOf(name);
          final broken = <String>{};
          final seen = <String>{};
          var namesCut = 0;
          void look() {
            for (final e in find.byType(RichText).evaluate()) {
              if (Scrollable.maybeOf(e) == null) continue;
              final p = e.renderObject! as RenderParagraph;
              if (!p.hasSize || !p.attached) continue;
              final text = p.text.toPlainText();
              if (p.didExceedMaxLines && e.findAncestorWidgetOfExactType<CustomerLine>() != null) namesCut++;
              for (final n in numbers) {
                final at = text.indexOf(n);
                if (at < 0) continue;
                seen.add(n);
                if (p.didExceedMaxLines) broken.add('"$n" shares a cut text "$text"');
                final boxes = p.getBoxesForSelection(TextSelection(baseOffset: at, extentOffset: at + n.length));
                final lines = _boxLines(boxes);
                if (lines != 1) broken.add('"$n" on $lines lines');
                if (boxes.any((b) => b.left < -0.5 || b.right > p.size.width + 0.5 || b.bottom > p.size.height + 0.5)) {
                  broken.add('"$n" not drawn whole');
                }
                for (final b in boxes) {
                  final r = MatrixUtils.transformRect(p.getTransformTo(null), b.toRect());
                  if (r.left < -0.5 || r.right > size.width + 0.5) broken.add('"$n" $r outside the screen');
                }
              }
            }
          }

          // ⓘ ওপর থেকে শেষ পর্যন্ত ধাপে ধাপে — সরু পর্দায় শেষে গেলে ওপরের সারি আর আঁকা থাকে না
          look();
          for (var step = 0; step < 40; step++) {
            final more = tester.stateList<ScrollableState>(find.byType(Scrollable)).any((st) {
              final q = st.position;
              return q.axis == Axis.vertical && q.hasContentDimensions && q.pixels < q.maxScrollExtent - 0.5;
            });
            if (!more) break;
            await _scrollOneStep(tester);
            look();
          }
          expect(broken, isEmpty, reason: '⛔ a paper number is cut, broken or off the screen on $at');
          if (numbers.isNotEmpty) expect(seen, isNotEmpty, reason: 'no paper number of the data is drawn on $at');
          // ⓘ দাবি যেন আসল অবস্থায় পরীক্ষা হয়: ৩২০dp ২০০%-এ আদায়ের অন্তত একটা নাম সত্যিই কাটা
          if (name == 'SR collections' && width == 320 && scale == 2) {
            expect(namesCut, greaterThan(0), reason: 'no name is cut on $at — the number claim is not tested where it matters');
          }
        });
      }
    }
  }

  // ⭐ কাটা নাম কখনো হারায় না (fe, ২৪ অক্টোবর ২০২৬, ০.৪.৪৮): তালিকার যে সারিতে "নাম · পয়েন্ট · শাখা" "…"-এ কাটে, সেখানে
  // TalkBack পুরো লেখা পড়ে, আর ছুঁয়ে ধরে রাখলে টুলটিপে পুরোটা ([CustomerLine])। পুরো লেখা সারির নিজের তথ্য থেকে নেওয়া
  // (PaperRow.party, AmountLine.label, DesignListRow.name) — লাইনের নিজের লেখা থেকে নয়।
  for (final name in _withTotals) {
    testWidgets('$name 360dp 100%: a cut name is read in full by TalkBack and shown in full on a long-press', (tester) async {
      final handle = tester.ensureSemantics();
      await open(tester, cases[name]!, _widths[360]!, 1);
      // ⓘ শেষে — নিচের সারিগুলোও (দোকানের বকেয়া) আঁকা থাকে
      await _scrollToEnd(tester);
      String? fullOf(Element e) {
        String? found;
        e.visitAncestorElements((a) {
          found = switch (a.widget) {
            PaperRow(:final party) => party,
            AmountLine(:final label) => label,
            DesignListRow(name: final n) => n,
            _ => null,
          };
          return found == null;
        });
        return found;
      }

      final cut = <Element>[];
      for (final e in find.byType(CustomerLine).evaluate()) {
        if (Scrollable.maybeOf(e) == null) continue;
        final p = tester.renderObject<RenderParagraph>(find.descendant(of: find.byElementPredicate((x) => x == e), matching: find.byType(RichText)).first);
        if (p.didExceedMaxLines) cut.add(e);
      }
      expect(cut, isNotEmpty, reason: 'no name is cut on $name at 360dp — the claim would be empty');
      final missing = <String>[];
      for (final e in cut) {
        final full = fullOf(e);
        expect(full, isNotNull, reason: 'a cut name outside the known rows');
        final line = find.byElementPredicate((x) => x == e);
        final spoken = tester.getSemantics(line).label;
        if (!spoken.contains(full!)) missing.add('TalkBack says "$spoken", not the full "$full"');
        await tester.ensureVisible(line);
        await tester.pumpAndSettle();
        // ⓘ টুলটিপ নতুন একটা লেখা আঁকে — ছোঁয়ার আগে আর পরে পুরো লেখার গোনা
        final before = find.textContaining(full).evaluate().length;
        await tester.longPress(line);
        await tester.pump(const Duration(milliseconds: 300));
        if (find.textContaining(full).evaluate().length <= before) {
          missing.add('a long-press shows no tooltip with the full "$full"');
        }
        await tester.pump(const Duration(seconds: 3));
        await tester.pumpAndSettle();
      }
      expect(missing, isEmpty, reason: '⛔ a cut name on $name cannot be reached in full');
      handle.dispose();
    });
  }

  for (final name in _withTotals) {
    final c = cases[name]!;
    // ⭐ fe-র শর্ত ১: কোনো অঙ্ক বাদ পড়ে না — dd0c247e6-এর বারের প্রতিটা লেখা এখনো বারে
    testWidgets('$name 412dp 100%: nothing the bottom bar showed at dd0c247e6 is dropped', (tester) async {
      await open(tester, c, _widths[412]!, 1);
      final now = _barTexts(tester);
      // ignore: avoid_print
      print('BAR $name: $now');
      expect(_dropped(_barAtDd0c247e6[name]!, now), isEmpty,
          reason: '⛔ the bottom bar dropped what it showed at dd0c247e6 (now: $now)');
    });

    // ⭐ fe-র শর্ত ২: প্রতিটা টাকা এক লাইনে, "…" নয়, পুরো আঁকা, বারের ভেতরে, বোতামের সাথে মেলে না
    for (final MapEntry(key: width, value: size) in _widths.entries) {
      for (final scale in [1.0, 2.0]) {
        testWidgets('$name ${width}dp ${(scale * 100).round()}%: every amount in the bar is whole, on one line, inside the bar, clear of the button',
            (tester) async {
          final fab = await open(tester, c, size, scale);
          final bar = tester.getRect(find.byKey(const ValueKey('action-bar')));
          final fabRect = tester.getRect(fab);
          final broken = <String>[];
          var amounts = 0;
          for (final e in find.descendant(of: find.byKey(const ValueKey('totals-bar')), matching: find.byType(RichText)).evaluate()) {
            final p = e.renderObject! as RenderParagraph;
            final text = p.text.toPlainText();
            final rect = _globalRect(p);
            if (p.didExceedMaxLines) broken.add('"$text" cut ("…")');
            // ⓘ আজকের নমুনায় না কাটলেও: বারের কোনো লেখা "…"-তে কাটার বা লাইন বেঁধে দেওয়ার মতো করে বসানো নয় — বড় অঙ্ক বা লম্বা
            // নামেও কাটবে না
            if (p.overflow == TextOverflow.ellipsis || p.maxLines != null) {
              broken.add('"$text" is set to be cut (maxLines ${p.maxLines}, ${p.overflow})');
            }
            if (p.getMaxIntrinsicWidth(double.infinity) > p.size.width + 0.5) broken.add('"$text" not drawn whole');
            if (!text.contains('৳')) continue;
            amounts++;
            final lines = _lineCount(p, text);
            if (lines != 1) broken.add('"$text" on $lines lines');
            if (!(bar.contains(rect.topLeft) && bar.contains(rect.bottomRight - const Offset(0.01, 0.01)))) {
              broken.add('"$text" $rect outside the bar $bar');
            }
            if (rect.overlaps(fabRect)) broken.add('"$text" $rect under the button $fabRect');
          }
          expect(amounts, greaterThan(0), reason: 'no amount in the bar');
          expect(broken, isEmpty, reason: '⛔ an amount in the bottom bar is cut, broken or covered');
        });
      }
    }
  }

  // ⭐ চওড়া পর্দায় (খাড়া বার) বোতাম পাতার ওপরে ভাসে না — বারের মাথায় বসে, একই নাম, একই আইকন, একই কাজ (Material 3: রেলের
  // leading; fe, ২২ অক্টোবর ২০২৬)। গড়ানোর আগেই কিছু ঢাকা পড়ে না। ⓘ ০.৪.৪৮-এ অপরিবর্তিত।
  Finder floating() => find.byWidgetPredicate((w) => w is FloatingActionButton && w.key != const ValueKey('rail-fab'));
  final screens = <String, ({String initial, String label, Future<void> Function(WidgetTester) opened})>{
    'owner home': (
      initial: '/home',
      label: 'নতুন',
      opened: (tester) async => expect(find.byKey(const ValueKey('fab-choice-/home/new-order')), findsOneWidget),
    ),
    'collections': (
      initial: '/home/collections',
      // ⛔ ২০ নভেম্বর ২০২৬: "নতুন আদায়" নয় — টাকা জমার খবর, জমার খবরের চাবিতে
      label: 'টাকা জমার খবর',
      opened: (tester) async => expect(find.text('DEPOSIT REPORT SCREEN'), findsOneWidget),
    ),
    'orders': (
      initial: '/home/orders',
      label: 'নতুন অর্ডার',
      opened: (tester) async => expect(find.text('NEW ORDER SCREEN'), findsOneWidget),
    ),
  };
  for (final MapEntry(key: name, value: s) in screens.entries) {
    for (final MapEntry(key: size, value: dp) in {'1280x800': const Size(1280, 800), '800x1280': const Size(800, 1280)}.entries) {
      testWidgets('$name $size: no floating button, nothing covered before any scroll; the rail carries the action', (tester) async {
        setScreenSize(tester, dp);
        await tester.runAsync(seedMainData);
        await tester.pumpWidget(_app(s.initial, scale: 1, user: s.initial == '/home/collections' ? _depositor : null));
        await tester.pumpAndSettle();
        expect(floating(), findsNothing, reason: 'a FAB floats over the page');
        expect(find.byKey(const ValueKey('action-bar')), findsNothing, reason: 'the tablet keeps the action in the rail');
        final rail = find.byKey(const ValueKey('rail-fab'));
        expect(rail, findsOneWidget);
        expect(find.ancestor(of: rail, matching: find.byType(RoleRail)), findsOneWidget);
        // ⓘ একই নাম — চওড়া বারে লেখায়, সরু বারে ছুঁয়ে-ধরার লেখায়; একই আইকন
        final railFab = tester.widget<FloatingActionButton>(rail);
        expect(railFab.tooltip == s.label || find.descendant(of: rail, matching: find.text(s.label)).evaluate().isNotEmpty, isTrue,
            reason: 'the rail does not say "${s.label}"');
        final screenFab = find.byType(BarFab);
        if (screenFab.evaluate().isNotEmpty) {
          final b = tester.widget<BarFab>(screenFab);
          expect(b.label, s.label);
          expect(find.descendant(of: rail, matching: find.byIcon(b.icon)), findsOneWidget);
        }
        await tester.tap(rail);
        await tester.pumpAndSettle();
        await s.opened(tester);
      });
    }
  }

  // ⓘ মোটের পট্টি একই পট্টি — বারের ভেতরে বসলেও TotalsBar
  testWidgets('orders 412dp: the totals in the bar are the shared TotalsBar', (tester) async {
    await open(tester, cases['orders']!, _widths[412]!, 1);
    expect(find.descendant(of: find.byKey(const ValueKey('action-bar')), matching: find.byType(TotalsBar)), findsOneWidget);
  });
}
