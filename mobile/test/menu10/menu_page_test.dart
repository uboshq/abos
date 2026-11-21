import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/profile/me_profile_api.dart';
import '../fakes/fake_me_profile_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/features/menu/menu_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/bar_fonts.dart';
import 'menu_host.dart';

/// ⭐ ≡ মেনু (আইটেম ১০; মালিকের অনুমোদিত নমুনা "খ", ২০ নভেম্বর ২০২৬) — সব অংশ, শর্টকাটের ৮ + "আরও দেখুন", "সব শাখা"-র
/// নোট, আজকের কাজের দুই উৎস, ভাঁজ, প্রিন্টার আর সিঙ্ক, বের হওয়া, আর তিন রূপে ৩৬০ ও ৪১২ চওড়ায় কিছু উপচে পড়ে না।
void main() {
  late AppLooks saved;

  setUpAll(loadBarFonts);

  setUp(() {
    saved = AppLooks.instance;
    AppLooks.instance = AppLooks(read: (_) async => null, write: (_, __) async {});
    AppLooks.instance.motion.value = false; // ⓘ কাচের ছোপ স্থির — pumpAndSettle থামে
  });
  tearDown(() => AppLooks.instance = saved);

  Finder key(String k) => find.byKey(ValueKey(k));
  final scroller = find.byType(Scrollable).first;

  Future<void> reach(WidgetTester tester, String k) async {
    await tester.scrollUntilVisible(key(k), 200, scrollable: scroller);
    await tester.pumpAndSettle();
  }

  Future<void> pump(WidgetTester tester, AppLook look, Widget page,
      {MeFeatures? features = const MeFeatures(profilePage: true), MeProfileApi? api, Size size = const Size(412, 915)}) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(menuHost(look, page, features: features, api: api));
    await tester.pumpAndSettle();
  }

  testWidgets('every section renders, top to bottom', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.abos, fullMenu(log));
    final l = AppLocale.l10n;
    expect(key('menu-self'), findsOneWidget);
    expect(find.text('Shuvo Ahmed'), findsOneWidget);
    expect(find.text('Owner · ADI Corporation · TD Branch'), findsOneWidget);
    expect(find.text(l.menuPageSeeProfile), findsOneWidget);
    expect(key('menu-workspace'), findsOneWidget);
    expect(find.text(l.workspacePickerTitle), findsOneWidget);
    expect(find.text(l.menuPageShortcuts), findsOneWidget);
    await reach(tester, 'menu-recent-0');
    expect(find.text(l.homeRecent), findsOneWidget);
    await reach(tester, 'menu-today');
    expect(find.text(l.lookTodayWork), findsOneWidget);
    for (final f in ['help', 'settings', 'about']) {
      await reach(tester, 'menu-fold-$f');
    }
    await reach(tester, 'menu-logout');
    await reach(tester, 'menu-version');
    expect(find.text(l.menuPageAppVersion('0.4.62')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the self row opens the Me page, "বদলান" opens the picker', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.abos, fullMenu(log));
    await tester.tap(key('menu-self'));
    await tester.tap(key('menu-switch'));
    expect(log, ['profile', 'switch']);
  });

  testWidgets('shortcuts: the first 8, the rest after "আরও দেখুন", counts on the tile', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.abos, fullMenu(log));
    for (final a in tenShortcuts.take(8)) {
      expect(key('menu-tile-${a.path}'), findsOneWidget);
    }
    for (final a in tenShortcuts.skip(8)) {
      expect(key('menu-tile-${a.path}'), findsNothing);
    }
    expect(key('menu-tile-approvals-count'), findsOneWidget);
    expect(find.descendant(of: key('menu-tile-approvals-count'), matching: find.text('3')), findsOneWidget);
    expect(key('menu-tile-today-count'), findsNothing);

    await reach(tester, 'menu-more');
    await tester.tap(key('menu-more'));
    await tester.pumpAndSettle();
    for (final a in tenShortcuts) {
      await reach(tester, 'menu-tile-${a.path}');
    }
    expect(key('menu-more'), findsNothing);

    await tester.tap(key('menu-tile-payslips'));
    expect(log, ['open:payslips']);
  });

  testWidgets('eight or fewer shortcuts — no "আরও দেখুন"', (tester) async {
    await pump(tester, AppLook.abos, fullMenu([], shortcuts: tenShortcuts.take(8).toList()));
    expect(key('menu-more'), findsNothing);
  });

  testWidgets('recent: the last 3 opened, a tap reopens', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.abos, fullMenu(log));
    await reach(tester, 'menu-recent-2');
    expect(key('menu-recent-3'), findsNothing);
    expect(find.text('Fourth — not shown'), findsNothing);
    expect(find.text(AppLocale.l10n.menuPageRecentPaper), findsOneWidget);
    await tester.tap(key('menu-recent-0'));
    expect(log, ['open:paper/money-in/42']);
  });

  testWidgets('"সব শাখা — কেবল দেখা" only while all branches are chosen', (tester) async {
    await pump(tester, AppLook.abos, fullMenu([]));
    expect(key('menu-all-branches-note'), findsNothing);
    await pump(tester, AppLook.abos, fullMenu([], allBranches: true));
    expect(key('menu-all-branches-note'), findsOneWidget);
    expect(find.text(AppLocale.l10n.menuPageAllBranchesNote), findsOneWidget);
  });

  testWidgets('today: the server counts when profilePage is on', (tester) async {
    final log = <String>[];
    final api = FakeMeProfileApi(todayCounts: const MeToday(approvalsWaiting: 11, unreadNotices: 22, dayClosePending: 2));
    await pump(tester, AppLook.abos, fullMenu(log), api: api);
    await reach(tester, 'menu-today');
    expect(api.calls, contains('today'));
    String countOf(String k) =>
        tester.widget<Text>(find.descendant(of: key('$k-count'), matching: find.byType(Text))).data!;
    expect(countOf('menu-today-approvals'), '11');
    expect(countOf('menu-today-notices'), '22');
    expect(countOf('menu-today-day-close'), '2');
    await tester.tap(key('menu-today-day-close'));
    await tester.tap(key('menu-today-notices'));
    await tester.tap(key('menu-today-approvals'));
    expect(log, ['day-close', 'notices', 'approvals']);
  });

  testWidgets('today: the passed-in counts on an old server, no day-close row', (tester) async {
    final api = FakeMeProfileApi();
    await pump(tester, AppLook.abos, fullMenu([]), features: MeFeatures.none, api: api);
    await reach(tester, 'menu-today');
    expect(api.calls, isNot(contains('today')));
    String countOf(String k) =>
        tester.widget<Text>(find.descendant(of: key('$k-count'), matching: find.byType(Text))).data!;
    expect(countOf('menu-today-approvals'), '5');
    expect(countOf('menu-today-notices'), '7');
    expect(key('menu-today-day-close'), findsNothing);
  });

  testWidgets('today: not an approver (null count) on an old server — no approvals row', (tester) async {
    await pump(tester, AppLook.abos, fullMenu([], approvals: null), features: null);
    await reach(tester, 'menu-today');
    expect(key('menu-today-approvals'), findsNothing);
    expect(key('menu-today-notices'), findsOneWidget);
  });

  testWidgets('folds open and close; the chevron turns', (tester) async {
    await pump(tester, AppLook.abos, fullMenu([]));
    await reach(tester, 'menu-fold-help');
    expect(key('menu-sub-help-centre'), findsNothing);
    await tester.tap(key('menu-fold-help'));
    await tester.pumpAndSettle();
    expect(key('menu-sub-help-centre'), findsOneWidget);
    expect(key('menu-sub-report-problem'), findsOneWidget);
    expect(tester.widget<AnimatedRotation>(key('menu-fold-help-chevron')).turns, 0.5);
    await tester.tap(key('menu-fold-help'));
    await tester.pumpAndSettle();
    expect(key('menu-sub-help-centre'), findsNothing);
    expect(tester.widget<AnimatedRotation>(key('menu-fold-help-chevron')).turns, 0);
  });

  testWidgets('classic and glass start with every fold shut; Smart opens settings', (tester) async {
    for (final look in [AppLook.abos, AppLook.glass]) {
      await tester.pumpWidget(const SizedBox()); // ⓘ নতুন পাতা — আগের রূপের ভাঁজ-অবস্থা নয়
      await pump(tester, look, fullMenu([]));
      await reach(tester, 'menu-fold-about');
      expect(key('menu-fold-settings-body'), findsNothing, reason: look.name);
    }
    await tester.pumpWidget(const SizedBox());
    await pump(tester, AppLook.expressive, fullMenu([]));
    await reach(tester, 'menu-fold-settings');
    expect(key('menu-fold-settings-body'), findsOneWidget);
    expect(key('menu-fold-help-body'), findsNothing);
    expect(key('menu-fold-about-body'), findsNothing);
  });

  testWidgets('settings keeps the old Me tab: printer and sync status, and every row calls back', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.expressive, fullMenu(log));
    final rows = ['look', 'language', 'fingerprint', 'screenshot', 'notifications', 'printer', 'sync-status'];
    for (final r in rows) {
      await reach(tester, 'menu-sub-$r');
      await tester.tap(key('menu-sub-$r'));
    }
    expect(log, rows);
    expect(find.text(AppLocale.l10n.homePrinter), findsOneWidget);
    expect(find.text(AppLocale.l10n.homeSyncStatus), findsOneWidget);
  });

  testWidgets('a null callback hides its row', (tester) async {
    await pump(tester, AppLook.expressive, MenuPage(name: 'Shuvo Ahmed', onOpenPath: (_, __) async {}, onLogout: () {}));
    await reach(tester, 'menu-fold-about');
    expect(key('menu-sub-printer'), findsNothing);
    expect(key('menu-sub-sync-status'), findsNothing);
    expect(key('menu-fold-help'), findsNothing, reason: 'an empty fold is not drawn');
    expect(key('menu-fold-settings'), findsNothing, reason: 'an empty fold is not drawn');
    expect(key('menu-today'), findsNothing);
    expect(key('menu-shortcuts'), findsNothing);
    expect(key('menu-switch'), findsNothing);
  });

  testWidgets('about: the version, and "আপডেট দেখুন" calls back', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.abos, fullMenu(log));
    await reach(tester, 'menu-fold-about');
    expect(find.text(AppLocale.l10n.menuPageVersion('0.4.62')), findsOneWidget);
    await tester.tap(key('menu-fold-about'));
    await tester.pumpAndSettle();
    await reach(tester, 'menu-sub-check-update');
    await tester.tap(key('menu-sub-check-update'));
    expect(log, ['check-update']);
  });

  testWidgets('"বের হওয়া" calls onLogout', (tester) async {
    final log = <String>[];
    await pump(tester, AppLook.glass, fullMenu(log));
    await reach(tester, 'menu-logout');
    await tester.tap(key('menu-logout'));
    expect(log, ['logout']);
  });

  for (final look in AppLook.values) {
    for (final size in const [Size(360, 800), Size(412, 915)]) {
      testWidgets('${look.name} at ${size.width.toInt()}×${size.height.toInt()} — nothing overflows, every fold open',
          (tester) async {
        await pump(tester, look, fullMenu([], allBranches: true), size: size);
        expect(tester.takeException(), isNull);
        await reach(tester, 'menu-more');
        await tester.tap(key('menu-more'));
        await tester.pumpAndSettle();
        for (final f in ['help', 'settings', 'about']) {
          await reach(tester, 'menu-fold-$f');
          if (find.byKey(ValueKey('menu-fold-$f-body')).evaluate().isEmpty) {
            await tester.tap(key('menu-fold-$f'));
            await tester.pumpAndSettle();
          }
        }
        await reach(tester, 'menu-version');
        expect(tester.takeException(), isNull);
      });
    }
  }
}
