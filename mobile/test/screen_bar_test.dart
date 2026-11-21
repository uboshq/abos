import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:material_symbols_icons/symbols.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_screen_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/bar_palette.dart';
import 'package:abos_mobile/core/widgets/unsaved_guard.dart';

import 'support/bar_fonts.dart';

/// ⭐ ভেতরের পর্দার বার ([AbosScreenBar]) — সব পর্দায় "কোম্পানি · শাখা" (মালিকের নিয়ম), হোমের একই নীল বার; পর্দার নিজের
/// বোতাম দ্বিতীয় সারিতে, দুটোর বেশি হলে "⋮"; সেশন না থাকলে আগের সাদামাটা বার।
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

class _SignedOut extends AuthController {
  _SignedOut() {
    state = const AuthState.signedOut();
  }
}

const _user = AuthUser(id: 'u1', name: 'করিম', email: 'karim@adi.test', roles: ['sr'], permissions: []);

class _Calls {
  int search = 0, bell = 0, menu = 0, branch = 0, print = 0, share = 0, filter = 0, refresh = 0;
}

TopBarContext _context(_Calls calls, {bool canSwitch = true}) => TopBarContext(
      company: 'ADI Wholesale',
      companyCode: 'ADI',
      branch: 'ময়মনসিংহ',
      moduleOf: (path) => path == 'collections' ? 'বিক্রয়' : null,
      search: (_) async => calls.search++,
      openNotifications: (_) async => calls.bell++,
      openMenu: (_, __) async => calls.menu++,
      switchBranch: canSwitch ? (_) async => calls.branch++ : null,
      // ⓘ বার্তা চালু — বারে বার্তা-বোতাম থাকে
      messages: (_) async {},
    );

List<Widget> _actions(_Calls calls, int n) => [
      IconButton(key: const ValueKey('a-filter'), tooltip: 'ছাঁকনি', onPressed: () => calls.filter++, icon: const Icon(Symbols.filter_list)),
      IconButton(key: const ValueKey('a-refresh'), tooltip: 'নতুন করে আনা', onPressed: () => calls.refresh++, icon: const Icon(Symbols.refresh)),
      IconButton(key: const ValueKey('a-print'), tooltip: 'ছাপা', onPressed: () => calls.print++, icon: const Icon(Symbols.print)),
      IconButton(key: const ValueKey('a-share'), tooltip: 'ভাগ করা', onPressed: () => calls.share++, icon: const Icon(Symbols.share)),
    ].take(n).toList();

/// একটা ভেতরের পর্দা — নিজের Scaffold, বার [AbosScreenBar]
Widget _screen({required List<Widget> actions, String? module = 'বিক্রয়', PreferredSizeWidget? bottom, Widget? body}) => Scaffold(
      appBar: AbosScreenBar(title: 'আদায়', module: module, actions: actions, bottom: bottom),
      body: body ?? const SizedBox.expand(),
    );

Widget _app(Widget home,
    {TopBarContext? context, bool signedIn = true, bool dark = false, double width = 412, double scale = 1, int unread = 3}) {
  return ProviderScope(
    overrides: [
      authStateProvider.overrideWith((ref) => signedIn ? _SignedIn(_user) : _SignedOut()),
      topBarContextProvider.overrideWith((ref) => context),
      navBadgesProvider.overrideWith((ref) => NavBadges(unread: unread)),
    ],
    child: MediaQuery(
      data: MediaQueryData(
        size: Size(width, 640),
        textScaler: TextScaler.linear(scale),
        padding: const EdgeInsets.only(top: 24),
        viewPadding: const EdgeInsets.only(top: 24),
        platformBrightness: dark ? Brightness.dark : Brightness.light,
      ),
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: withBarFonts(AppTheme.light()),
        darkTheme: withBarFonts(AppTheme.dark()),
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
        builder: (context, child) => RepaintBoundary(key: const ValueKey('shot'), child: child),
        home: home,
      ),
    ),
  );
}

Future<void> _pump(WidgetTester tester, Widget app, {double width = 412, double height = 640}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(app);
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(loadBarFonts);
  setUp(SyncStatus.instance.reset);

  group('without a session it is the old plain bar', () {
    testWidgets('no Riverpod at all (a bare screen test)', (tester) async {
      final calls = _Calls();
      await _pump(tester, MaterialApp(home: _screen(actions: _actions(calls, 2))));
      expect(find.byKey(const ValueKey('screen-bar-plain')), findsOneWidget);
      expect(find.descendant(of: find.byType(AppBar), matching: find.text('আদায়')), findsOneWidget);
      expect(find.byTooltip('ছাঁকনি'), findsOneWidget);
      expect(find.byKey(const ValueKey('top-search')), findsNothing);
    });
    // ⛔ ২০ অক্টোবর ২০২৬: preferredSize নীল বারের ৪৮০dp ছাদ — সাদামাটা বার পুরো ছাদ জুড়ে বসে পর্দার অর্ধেক ঢেকে দিচ্ছিল
    testWidgets('the plain bar keeps the old AppBar height, with or without tabs, and the body starts right under it', (tester) async {
      await _pump(tester, MaterialApp(home: _screen(actions: const [], body: const SizedBox.expand(key: ValueKey('plain-body')))));
      expect(tester.getSize(find.byKey(const ValueKey('screen-bar-plain'))).height, kToolbarHeight);
      expect(tester.getTopLeft(find.byKey(const ValueKey('plain-body'))).dy, kToolbarHeight);

      await _pump(
        tester,
        const MaterialApp(
          home: DefaultTabController(
            length: 2,
            child: Scaffold(
              appBar: AbosScreenBar(title: 'আদায়', bottom: TabBar(tabs: [Tab(text: 'আজ'), Tab(text: 'মাস')])),
              body: SizedBox.expand(key: ValueKey('plain-body')),
            ),
          ),
        ),
      );
      expect(tester.getSize(find.byKey(const ValueKey('screen-bar-plain'))).height, kToolbarHeight + kTextTabBarHeight);
      expect(tester.getTopLeft(find.byKey(const ValueKey('plain-body'))).dy, kToolbarHeight + kTextTabBarHeight);
    });
    testWidgets('Riverpod but nobody signed in', (tester) async {
      await _pump(tester, _app(_screen(actions: const []), context: _context(_Calls()), signedIn: false));
      expect(find.byKey(const ValueKey('screen-bar-plain')), findsOneWidget);
    });
    testWidgets('signed in but the home has said nothing yet', (tester) async {
      await _pump(tester, _app(_screen(actions: const [])));
      expect(find.byKey(const ValueKey('screen-bar-plain')), findsOneWidget);
    });
  });

  group('with a session it is the home bar: company · branch on every screen', () {
    testWidgets('company, branch, › module › page, and TalkBack says it all', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _app(_screen(actions: const []), context: _context(_Calls())));
      expect(find.byKey(const ValueKey('screen-bar')), findsOneWidget);
      expect(find.text('ADI Wholesale'), findsOneWidget);
      expect(find.text('ময়মনসিংহ'), findsOneWidget);
      expect(find.text('› বিক্রয় › আদায়'), findsOneWidget);
      expect(tester.getSemantics(find.byKey(const ValueKey('top-title'))).getSemanticsData().label,
          'ADI Wholesale, ময়মনসিংহ শাখা, বিক্রয়, আদায়');
      // ⓘ ২০ নভেম্বর ২০২৬ (নমুনা v8): ঘণ্টা নিচের বারের "নোটিশ" ঘরে; উপরে খোঁজা আর বার্তা
      expect(find.byTooltip('বিজ্ঞপ্তি, ৩টা না-পড়া'), findsNothing);
      expect(find.byTooltip('বার্তা'), findsOneWidget);
      expect(find.byTooltip('পিছনে'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('the module comes from the route when the screen does not name it', (tester) async {
      final router = GoRouter(initialLocation: '/home/collections', routes: [
        GoRoute(path: '/home', builder: (_, __) => const SizedBox(), routes: [
          GoRoute(path: 'collections', builder: (_, __) => _screen(actions: const [], module: null)),
        ]),
      ]);
      addTearDown(router.dispose);
      tester.view.physicalSize = const Size(412, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(ProviderScope(
        overrides: [
          authStateProvider.overrideWith((ref) => _SignedIn(_user)),
          topBarContextProvider.overrideWith((ref) => _context(_Calls())),
        ],
        child: MaterialApp.router(theme: AppTheme.light(), routerConfig: router),
      ));
      await tester.pumpAndSettle();
      expect(find.text('› বিক্রয় › আদায়'), findsOneWidget);
    });

    // ⓘ ১৩ নভেম্বর ২০২৬ — মালিকের সিদ্ধান্ত "ছবি নিচের বারে": ভেতরের পর্দার বারে আর ছবি নেই; নিজের মেনু নিচের বারের "আমি" ঘরে
    testWidgets('search and the branch do what they do on the home; no bell and no photo on the bar', (tester) async {
      final calls = _Calls();
      await _pump(tester, _app(_screen(actions: const []), context: _context(calls)));
      expect(find.byKey(const ValueKey('header-avatar')), findsNothing, reason: 'the photo lives on the bottom bar (আমি)');
      expect(find.byKey(const ValueKey('top-bell')), findsNothing, reason: 'ঘণ্টা নিচের বারের "নোটিশ" ঘরে');
      await tester.tap(find.byKey(const ValueKey('top-search')));
      await tester.tap(find.byKey(const ValueKey('workspace-header')));
      await tester.pumpAndSettle();
      expect([calls.search, calls.bell, calls.menu, calls.branch], [1, 0, 0, 1]);
    });

    testWidgets('one branch: nothing to switch, no ▾', (tester) async {
      await _pump(tester, _app(_screen(actions: const []), context: _context(_Calls(), canSwitch: false)));
      expect(find.byKey(const ValueKey('workspace-header')), findsNothing);
      expect(find.byKey(const ValueKey('top-chevron')), findsNothing);
    });

    testWidgets('← asks first when a form is half-written (maybePop, UnsavedGuard)', (tester) async {
      await _pump(
          tester,
          _app(
              Builder(
                builder: (context) => Scaffold(
                  body: Center(
                    child: FilledButton(
                      key: const ValueKey('open'),
                      onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
                          builder: (_) => UnsavedGuard(dirty: true, child: _screen(actions: const [])))),
                      child: const Text('খুলুন'),
                    ),
                  ),
                ),
              ),
              context: _context(_Calls())));
      await tester.tap(find.byKey(const ValueKey('open')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('top-leading')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('unsaved-guard')), findsOneWidget, reason: '⛔ লেখা ফেলে চলে গেল');
      await tester.tap(find.byKey(const ValueKey('unsaved-leave')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('open')), findsOneWidget);
    });
  });

  // ⓘ ১৩ নভেম্বর ২০২৬ ("ছবি নিচের বারে"): ডান কোণে এখন খোঁজা আর ঘণ্টা (কোপাইলট থাকলে তা-ও) — ছবি নিচের বারে
  group('screen actions sit on the second row, never beside search and bell', () {
    for (final n in [0, 2, 4]) {
      for (final width in [320.0, 412.0]) {
        testWidgets('$n actions at ${width.toInt()} dp', (tester) async {
          final calls = _Calls();
          await _pump(tester, _app(_screen(actions: _actions(calls, n)), context: _context(calls), width: width), width: width);
          expect(tester.takeException(), isNull);
          // ⓘ ডান কোণে সর্বোচ্চ তিনটা (Material 3) — কোপাইলট, খোঁজা, ঘণ্টা; পর্দার বোতাম তাদের সারিতে নয়
          final topRow = tester.getRect(find.byKey(const ValueKey('top-search'))).center.dy;
          for (final a in _actions(calls, n)) {
            final f = find.byKey(a.key!);
            if (f.evaluate().isEmpty) continue;
            expect(tester.getRect(f).center.dy, greaterThan(topRow + 24), reason: '⛔ ${(a as IconButton).tooltip} উপরের সারিতে');
          }
          expect(find.byKey(const ValueKey('top-action-row')), n == 0 ? findsNothing : findsOneWidget);
          expect(find.byKey(const ValueKey('top-overflow')), n > 2 ? findsOneWidget : findsNothing);
          final visible = [for (final k in ['a-filter', 'a-refresh', 'a-print', 'a-share']) if (find.byKey(ValueKey(k)).evaluate().isNotEmpty) k];
          expect(visible.length, n > 2 ? 2 : n);
          // ⓘ সব চাপার জায়গা অন্তত ৪৮dp
          for (final k in [...visible, if (n > 2) 'top-overflow', 'top-leading', 'top-search', 'top-messages']) {
            final size = tester.getSize(find.byKey(ValueKey(k)));
            expect(size.width >= 48 && size.height >= 48, isTrue, reason: '⛔ $k $size');
          }
          // ⓘ পাতার নাম সারির বাঁয়ে থাকে, কাটে না
          final crumbs = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-crumbs')));
          expect(crumbs.didExceedMaxLines, isFalse);
        });
      }
    }

    testWidgets('the rest go into ⋮ with their own names, and still work', (tester) async {
      final calls = _Calls();
      await _pump(tester, _app(_screen(actions: _actions(calls, 4)), context: _context(calls)));
      await tester.tap(find.byKey(const ValueKey('a-filter')));
      expect(calls.filter, 1);
      expect(find.byTooltip('আরও'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('top-overflow')));
      await tester.pumpAndSettle();
      expect(find.text('ছাপা'), findsOneWidget);
      expect(find.text('ভাগ করা'), findsOneWidget);
      await tester.tap(find.text('ভাগ করা'));
      await tester.pumpAndSettle();
      expect(calls.share, 1);
    });

    testWidgets('a TabBar under the bar still works, in the bar colours at 4.5:1', (tester) async {
      await _pump(
          tester,
          _app(
              DefaultTabController(
                length: 2,
                child: _screen(
                    actions: const [],
                    bottom: const TabBar(tabs: [Tab(text: 'আজ'), Tab(text: 'সব')]),
                    body: const TabBarView(children: [Text('আজকের তালিকা'), Text('সবার তালিকা')])),
              ),
              context: _context(_Calls())));
      expect(find.byKey(const ValueKey('top-bottom')), findsOneWidget);
      expect(find.text('আজকের তালিকা'), findsOneWidget);
      await tester.tap(find.text('সব'));
      await tester.pumpAndSettle();
      expect(find.text('সবার তালিকা'), findsOneWidget);
      const c = BarPalette.light;
      final under = tester.getRect(find.byKey(const ValueKey('top-bottom')));
      expect(under.top, greaterThanOrEqualTo(tester.getRect(find.byKey(const ValueKey('top-bar'))).bottom));
      final label = DefaultTextStyle.of(tester.element(find.text('সব'))).style.color!;
      expect(AppColors.contrast(label, c.bar), greaterThanOrEqualTo(4.5));
      final other = DefaultTextStyle.of(tester.element(find.text('আজ'))).style.color!;
      expect(AppColors.contrast(other, c.bar), greaterThanOrEqualTo(4.5));
    });
  });

  group('goldens: an inner screen with 0, 2 and 4 actions', () {
    for (final dark in [false, true]) {
      final mode = dark ? 'dark' : 'light';
      for (final n in [0, 2, 4]) {
        for (final width in [320.0, 412.0]) {
          testWidgets('$n actions ${width.toInt()} $mode', (tester) async {
            final calls = _Calls();
            tester.view.physicalSize = Size(width, 200);
            tester.view.devicePixelRatio = 1;
            addTearDown(tester.view.reset);
            await tester.pumpWidget(_app(_screen(actions: _actions(calls, n)), context: _context(calls), dark: dark, width: width));
            await tester.pumpAndSettle();
            await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/top_bar/screen_${n}actions_${width.toInt()}_$mode.png'));
          });
        }
      }
    }
  });
}
