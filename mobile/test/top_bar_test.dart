import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/config/app_config.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/avatar_menu.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/bar_palette.dart';
import 'package:abos_mobile/core/theme/theme_choice.dart';
import 'package:abos_mobile/core/locale/locale_choice.dart';
import 'package:abos_mobile/core/widgets/unsaved_guard.dart';

import 'support/bar_fonts.dart';

/// ⭐ উপরের বার আর ছবির মেনু — মালিকের নকশা ৩ (§২, §৩, §৬, §৭) আর অনুমোদিত পুরো-পাতার নমুনা, পাতা ২ (২০ অক্টোবর ২০২৬)।
/// প্রতিটা দাবি একটা নিয়ম; প্রতিটার জন্য হাতে মিউট্যান্ট চালানো হয়েছে (নিয়ম ভাঙলে লাল)।
///
/// <p>ⓘ ছবি-টেস্ট আসল ফন্টে (Roboto, Shonar Bangla, Material Symbols) — নইলে সব অক্ষর চৌকো বাক্স। ফন্ট এই PC-র; ছবি
/// এক PC-তে বানানো, একই ফন্টে বারবার একই।
void main() {
  setUpAll(loadBarFonts);

  late ThemeChoices themes;
  setUp(() {
    themes = ThemeChoices(read: () async => null, write: (_) async {});
    ThemeChoices.instance = themes;
  });

  group('every target on the bar is at least 48 x 48 dp (WCAG 2.5.8, Material 3)', () {
    for (final width in [320.0, 360.0, 412.0]) {
      for (final scale in [1.0, 2.0]) {
        testWidgets('at ${width.toInt()} dp, ${(scale * 100).toInt()}% text', (tester) async {
          await _pump(tester, _owner(), width: width, scale: scale);
          // ⓘ ২০ নভেম্বর ২০২৬ (নমুনা v8): ঘণ্টার জায়গায় বার্তা — সাদামাটা আইকন, চাপার জায়গা তবু ৪৮
          for (final key in ['top-leading', 'workspace-header', 'top-search', 'top-messages', 'header-avatar']) {
            final size = tester.getSize(find.byKey(ValueKey(key)));
            expect(size.width, greaterThanOrEqualTo(48), reason: '⛔ $key চওড়ায় ${size.width}dp');
            expect(size.height, greaterThanOrEqualTo(48), reason: '⛔ $key উচ্চতায় ${size.height}dp');
          }
        });
      }
    }
  });

  group('every text on the bar and in the menu reads at 4.5:1 (WCAG 1.4.3)', () {
    for (final dark in [false, true]) {
      testWidgets(dark ? 'dark' : 'light', (tester) async {
        await _pump(tester, _owner(), dark: dark);
        final c = dark ? BarPalette.dark : BarPalette.light;
        final barColour = tester.widget<Material>(find.ancestor(of: find.byKey(const ValueKey('top-bar')), matching: find.byType(Material)).first).color!;
        expect(barColour, c.bar, reason: '⛔ বারের রং থিম থেকে আসেনি');
        final ctx = tester.element(find.byKey(const ValueKey('top-bar')));
        final live = TopBarColors.of(ctx, demo: false);
        final demo = TopBarColors.of(ctx, demo: true);
        final pairs = <String, (Color, Color)>{
          'বারের লেখা': (live.foreground, live.background),
          'মডিউলের লাইন': (live.crumb, live.background),
          'ডেমো বারের লেখা': (demo.foreground, demo.background),
          'ডেমো বারের দ্বিতীয় লাইন': (demo.crumb, demo.background),
          '"ডেমো" চিহ্ন': (demo.onPill, demo.pill),
          'ঘণ্টার গোনা': (c.onBadge, c.badge),
          'ছবির অক্ষর (গাঢ় দিকে)': (c.onAvatar, c.avatarTo),
          'ছবির অক্ষর (হালকা দিকে)': (c.onAvatar, c.avatarFrom),
          'অফলাইনের ফিতা': (c.onRibbon, c.ribbon),
          'মেনুর লেখা': (c.menuInk, c.menu),
          'মেনুর মলিন লেখা': (c.menuSub, c.menu),
          'রঙের সারির লেখা': (c.menuInk, c.menuRow),
          'রঙের সারিতে মলিন লেখা': (c.menuSub, c.menuRow),
          'বাছা রং': (c.onSelected, c.selected),
          'বের হওয়া': (c.danger, c.menu),
        };
        for (final MapEntry(key: what, value: (fg, bg)) in pairs.entries) {
          expect(AppColors.contrast(fg, bg), greaterThanOrEqualTo(4.5),
              reason: '⛔ $what: ${AppColors.contrast(fg, bg).toStringAsFixed(2)}:১');
        }
      });
    }
  });

  group('TalkBack reads the bar in Bangla', () {
    testWidgets('the title, search, messages and the photo', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'ময়মনসিংহ', crumbs: const ['বিক্রয়'], unread: 3, onOrgTap: () {}));
      final title = tester.getSemantics(find.byKey(const ValueKey('top-title'))).getSemanticsData();
      expect(title.label, 'ADI Wholesale, ময়মনসিংহ শাখা, বিক্রয়');
      expect(title.hasAction(SemanticsAction.tap), isTrue, reason: 'শাখা বদল চাপ দিয়ে খোলে');
      expect(find.byTooltip('বিজ্ঞপ্তি, ৩টা না-পড়া'), findsNothing, reason: 'ঘণ্টা নিচের বারের "নোটিশ" ঘরে');
      expect(find.byTooltip('বার্তা'), findsOneWidget);
      expect(find.byTooltip('আমার মেনু'), findsOneWidget);
      expect(find.byTooltip('খোঁজা'), findsOneWidget);
      expect(find.byTooltip('পুরো মেনু'), findsOneWidget);
      // ⓘ গোনার "3" আলাদা করে পড়ে না — কথাটা পুরো বাক্যে একবার
      expect(find.bySemanticsLabel(RegExp(r'^3$')), findsNothing);
      handle.dispose();
    });

    testWidgets('"সব শাখা" is not read as "সব শাখা শাখা"', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _owner());
      expect(tester.getSemantics(find.byKey(const ValueKey('top-title'))).getSemanticsData().label, 'ADI Wholesale, সব শাখা');
      handle.dispose();
    });
  });

  group('large text never breaks the bar and never cuts the branch', () {
    for (final width in [320.0, 360.0]) {
      for (final scale in [1.0, 1.3, 2.0]) {
        testWidgets('${width.toInt()} dp at ${(scale * 100).toInt()}%', (tester) async {
          await _pump(
              tester,
              _bar(
                  company: 'ADI Wholesale Distribution Limited',
                  branch: 'ময়মনসিংহ',
                  crumbs: const ['বিক্রয়', 'আদায়'],
                  unread: 120,
                  onOrgTap: () {},
                  leading: TopBarLeading.back),
              width: width,
              scale: scale);
          expect(tester.takeException(), isNull, reason: '⛔ বার ভাঙল');
          final branch = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-branch')));
          expect(branch.didExceedMaxLines, isFalse, reason: '⛔ শাখার নাম কাটা গেল');
          // ⓘ নমুনা v8: শাখা নিজের চিপে, নামের নিচে — খুব সরু জায়গায় চিপের ভেতরে দুই লাইনে নামতে পারে, কাটে না
          expect(_lines(branch), lessThanOrEqualTo(2), reason: '⛔ শাখার নাম তিন লাইনে ভাঙল');
          final crumbs = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-crumbs')));
          expect(crumbs.didExceedMaxLines, isFalse, reason: '⛔ মডিউলের নাম কাটা গেল');
          final r = tester.getRect(find.byKey(const ValueKey('top-branch')));
          expect(r.right, lessThanOrEqualTo(width), reason: '⛔ শাখা পর্দার বাইরে');
          final company = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-company')));
          expect(company.didExceedMaxLines, isTrue, reason: 'লম্বা কোম্পানির নাম "…" দিয়ে ছোট হয়');
          if (scale == 2.0) {
            expect(tester.getSize(find.byKey(const ValueKey('top-bar'))).height, greaterThan(AbosTopBar.rowHeight),
                reason: '২০০%-এ শিরোনাম দুই লাইনে নামে, বার লম্বা হয়');
          }
        });
      }
    }
  });

  // ⭐ ২০ নভেম্বর ২০২৬ (মালিকের "Ok", নমুনা v7–v8): কোম্পানির পুরো নাম, কখনো ছোট নাম ("ADI") নয়; "…" কেবল শেষ উপায়
  group('the company name is always the full name', () {
    for (final width in [320.0, 412.0]) {
      testWidgets('${width.toInt()} dp: the full name, never the code; the branch whole; TalkBack the full name', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, _owner(), width: width);
        expect(find.text('ADI Wholesale'), findsOneWidget);
        expect(find.text('ADI'), findsNothing, reason: '⛔ ছোট নাম');
        final company = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-company')));
        if (width >= 360) expect(company.didExceedMaxLines, isFalse);
        final branch = tester.renderObject<RenderParagraph>(find.byKey(const ValueKey('top-branch')));
        expect(branch.didExceedMaxLines, isFalse);
        expect(find.text('সব শাখা'), findsOneWidget);
        expect(tester.getSemantics(find.byKey(const ValueKey('top-title'))).getSemanticsData().label, 'ADI Wholesale, সব শাখা');
        handle.dispose();
      });
    }
    testWidgets('a long press on the name shows the full name and branch', (tester) async {
      await _pump(tester, _owner(), width: 320);
      await tester.longPress(find.byKey(const ValueKey('workspace-header')));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('ADI Wholesale · সব শাখা'), findsOneWidget);
      await tester.pumpAndSettle(const Duration(seconds: 2));
    });
  });

  // ⭐ ২০ নভেম্বর ২০২৬: ঘণ্টা উপরের বারে আর নেই — না-পড়ার গোনা নিচের বারের "নোটিশ" ঘরে (test/app_0458/looks_test.dart)
  group('the bell left the top bar', () {
    testWidgets('no bell and no count on the top bar, whatever the unread count', (tester) async {
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'সব শাখা', unread: 7, news: true));
      expect(find.byKey(const ValueKey('top-bell')), findsNothing);
      expect(find.text('7'), findsNothing);
    });
    test('the numbers', () {
      expect([badgeText(null), badgeText(0), badgeText(1), badgeText(99), badgeText(100)], [null, null, '1', '99', '99+']);
    });
  });

  group('offline: a yellow sentence right under the bar', () {
    testWidgets('says how many papers wait, in a full sentence', (tester) async {
      final status = SyncStatus();
      final net = StreamController<List<ConnectivityResult>>();
      addTearDown(net.close);
      status.watchNetwork(changes: net.stream);
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'ময়মনসিংহ', syncStatus: status, pendingCount: () => 3));
      expect(find.byKey(const ValueKey('top-offline')), findsNothing, reason: 'নেট আছে — ফিতা নেই');

      net.add([ConnectivityResult.none]);
      await tester.pumpAndSettle();
      expect(find.text('অফলাইন: ৩টা কাগজ পাঠানো বাকি, নেট এলে নিজেই যাবে'), findsOneWidget);
      final ribbon = tester.getRect(find.byKey(const ValueKey('top-offline')));
      final bar = tester.getRect(find.byKey(const ValueKey('top-bar')));
      expect(ribbon.top, bar.bottom, reason: 'বারের ঠিক নিচে');
      expect(tester.widget<Material>(find.byKey(const ValueKey('top-offline'))).color, BarPalette.light.ribbon);

      net.add([ConnectivityResult.wifi]);
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('top-offline')), findsNothing, reason: 'নেট ফিরলে ফিতা যায়');
    });
  });

  group('the demo server is marked', () {
    testWidgets('a distinct bar colour and the word ডেমো', (tester) async {
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'সব শাখা', demo: true));
      expect(find.text('ডেমো'), findsOneWidget);
      final colour = tester.widget<Material>(find.ancestor(of: find.byKey(const ValueKey('top-bar')), matching: find.byType(Material)).first).color;
      expect(colour, BarPalette.light.demoBar);
      expect(colour, isNot(BarPalette.light.bar));
    });
    testWidgets('the real books: no marker', (tester) async {
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'সব শাখা', demo: false));
      expect(find.text('ডেমো'), findsNothing);
    });
    test('only erp.adi.com.bd is the real server', () {
      expect(isDemoServer('https://erp.adi.com.bd/api/v1'), isFalse);
      expect(isDemoServer('https://demo.adi.com.bd/api/v1'), isTrue);
      expect(isDemoServer('http://10.0.2.2:8090/api/v1'), isTrue);
      expect(isDemoServer(), isFalse, reason: 'এই বিল্ড আসল সার্ভারে');
    });
  });

  group('the title', () {
    testWidgets('home: no second line; inside: › module › page', (tester) async {
      await _pump(tester, _owner());
      expect(find.byKey(const ValueKey('top-crumbs')), findsNothing);
      expect(find.byKey(const ValueKey('top-chevron')), findsOneWidget, reason: 'বদলানো যায় — ▾');
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'ময়মনসিংহ', crumbs: const ['বিক্রয়', 'আদায়'], leading: TopBarLeading.back));
      expect(find.text('› বিক্রয় › আদায়'), findsOneWidget);
      expect(find.byKey(const ValueKey('top-chevron')), findsNothing, reason: 'এক শাখা — ▾ নেই');
      expect(find.byKey(const ValueKey('workspace-header')), findsNothing, reason: 'এক শাখা — চাপার কিছু নেই');
      expect(find.byTooltip('পিছনে'), findsOneWidget);
    });

    testWidgets('a long press shows the whole name', (tester) async {
      await _pump(tester, _bar(company: 'ADI Wholesale Distribution Limited', branch: 'ময়মনসিংহ', onOrgTap: () {}), width: 320);
      await tester.longPress(find.byKey(const ValueKey('workspace-header')));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('ADI Wholesale Distribution Limited · ময়মনসিংহ'), findsOneWidget);
      await tester.pumpAndSettle(const Duration(seconds: 2));
    });
  });

  group('a branch switch with a half-written form asks first', () {
    testWidgets('dirty: asks, "থাকুন" keeps the form, "না রেখে বদলান" switches', (tester) async {
      var opened = 0;
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'সব শাখা', onOrgTap: () => opened++),
          body: const UnsavedGuard(dirty: true, child: Text('আধা-লেখা অর্ডার')));
      expect(UnsavedGuard.anyDirty, isTrue);

      await tester.tap(find.byKey(const ValueKey('workspace-header')));
      await tester.pumpAndSettle();
      expect(find.text('না রেখে বদলাবেন?'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('top-unsaved-stay')));
      await tester.pumpAndSettle();
      expect(opened, 0, reason: '⛔ জিজ্ঞেস না করে শাখা বদলের তালিকা খুলল');

      await tester.tap(find.byKey(const ValueKey('workspace-header')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('top-unsaved-go')));
      await tester.pumpAndSettle();
      expect(opened, 1);
    });

    testWidgets('clean: opens the switcher at once', (tester) async {
      var opened = 0;
      await _pump(tester, _bar(company: 'ADI Wholesale', branch: 'সব শাখা', onOrgTap: () => opened++),
          body: const UnsavedGuard(dirty: false, child: Text('খালি ফর্ম')));
      expect(UnsavedGuard.anyDirty, isFalse);
      await tester.tap(find.byKey(const ValueKey('workspace-header')));
      await tester.pumpAndSettle();
      expect(find.text('না রেখে বদলাবেন?'), findsNothing);
      expect(opened, 1);
    });
  });

  group('the avatar menu', () {
    const order = [
      'avatar-header',
      'avatar-profile',
      'avatar-company',
      'avatar-theme',
      'avatar-look',
      'avatar-lock',
      'avatar-settings',
      'avatar-logout',
    ];

    // ⭐ চেহারা ছবির মেনুতেই — মালিক, ২০ নভেম্বর ২০২৬ ("চেহারা eta nai"); চাপলে চেহারার পাতা খোলে
    testWidgets('the look page opens straight from the avatar menu', (tester) async {
      await _pump(tester, _owner(onMenu: (_) {}), height: 900);
      await tester.tap(find.byKey(const ValueKey('header-avatar')));
      await tester.pumpAndSettle();
      expect(find.text('চেহারা'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('avatar-look')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('avatar-menu')), findsNothing, reason: 'মেনু বন্ধ হয়নি');
      expect(find.text('গ্লাস'), findsWidgets, reason: 'চেহারার পাতা খোলেনি');
    });

    testWidgets('the photo opens it, items in the owner\'s order, each with a Material Symbol', (tester) async {
      AvatarChoice? picked;
      await _pump(tester, _owner(onMenu: (c) => picked = c), height: 900);
      await tester.tap(find.byKey(const ValueKey('header-avatar')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('avatar-ring')), findsOneWidget, reason: 'খোলা মেনুতে ছবির চারপাশে দাগ');

      final tops = [for (final k in order) tester.getTopLeft(find.byKey(ValueKey(k))).dy];
      for (var i = 1; i < tops.length; i++) {
        expect(tops[i], greaterThan(tops[i - 1]), reason: '⛔ ${order[i]} আছে ${order[i - 1]}-এর উপরে');
      }
      expect(find.text('আল-আমিন শুভ'), findsOneWidget);
      expect(find.text('মালিক · সব শাখা'), findsOneWidget);
      for (final t in ['আমার প্রোফাইল', 'কোম্পানি বদল', 'রং', 'পিন ও আঙুলের ছাপ', 'বের হওয়া']) {
        expect(find.text(t), findsOneWidget, reason: t);
      }
      // ⓘ সংস্করণ অ্যাপের ধ্রুবক থেকে
      expect(find.text('সেটিংস · সংস্করণ ${AppConfig.appVersion}'), findsOneWidget);
      // ⓘ প্রতিটা সারির আইকন Material Symbols-এর (ইমোজি নয়)
      for (final k in order.skip(1).where((k) => k != 'avatar-theme')) {
        final icon = tester.widget<Icon>(find.descendant(of: find.byKey(ValueKey(k)), matching: find.byType(Icon)).first);
        expect(icon.icon!.fontFamily, startsWith('MaterialSymbols'), reason: k);
      }
      // ⓘ মেনু বারের নিচে, ডানে
      final menu = tester.getRect(find.byKey(const ValueKey('avatar-menu')));
      expect(menu.top, greaterThanOrEqualTo(tester.getRect(find.byKey(const ValueKey('top-bar'))).bottom));
      expect(412 - menu.right, closeTo(10, 0.5));

      // ⭐ যা কাজ করে না তা মেনুতে নেই — কোনো বন্ধ সারি নয়, কোথাও "শীঘ্রই" নয় (মালিক, ২০ অক্টোবর ২০২৬)
      expect(find.byKey(const ValueKey('avatar-devices')), findsNothing);
      expect(find.textContaining('শীঘ্রই'), findsNothing);
      expect(find.textContaining('আমার ফোনগুলো'), findsNothing);
      final rows = find.descendant(of: find.byKey(const ValueKey('avatar-menu')), matching: find.byType(InkWell));
      expect(rows, findsWidgets);
      for (final row in tester.widgetList<InkWell>(rows)) {
        expect(row.onTap, isNotNull, reason: '⛔ মেনুতে বন্ধ সারি');
      }

      await tester.tap(find.byKey(const ValueKey('avatar-settings')));
      await tester.pumpAndSettle();
      expect(picked, AvatarChoice.settings);
      expect(find.byKey(const ValueKey('avatar-menu')), findsNothing);
    });

    test('the version line reads the app constant, never a written number', () {
      final src = File('lib/core/navigation/top_bar/avatar_menu.dart').readAsStringSync();
      // ⓘ লেখা এখন ARB-তে (0.4.43): "সেটিংস · সংস্করণ {version}" / "Settings · Version {version}" — সংখ্যাটা তবু ধ্রুবক থেকে
      // ⓘ সংখ্যাটা `version` থেকে, যার নিজের মান সবসময় ধ্রুবক — কেবল সোনালি ছবির পরীক্ষা স্থির সংখ্যা দেয়
      expect(src, contains('words.avatarMenuSettingsVersion(version)'));
      expect(src, contains('this.version = AppConfig.appVersion'));
      expect(src, contains('String version = AppConfig.appVersion'));
      for (final arb in ['lib/l10n/app_bn.arb', 'lib/l10n/app_en.arb']) {
        final text = File(arb).readAsStringSync();
        expect(RegExp(r'"avatarMenuSettingsVersion": "[^"]*\{version\}"').hasMatch(text), isTrue, reason: arb);
        expect(RegExp(r'(সংস্করণ|Version)\s*[0-9০-৯]').hasMatch(text), isFalse, reason: '⛔ সংস্করণ হাতে লেখা ($arb)');
      }
      expect(RegExp(r'সংস্করণ\s*[0-9০-৯]').hasMatch(src), isFalse, reason: '⛔ সংস্করণ হাতে লেখা');
    });

    testWidgets('no company change offered to somebody in one company', (tester) async {
      await _pump(tester, _owner(canSwitchCompany: false));
      await tester.tap(find.byKey(const ValueKey('header-avatar')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('avatar-company')), findsNothing);
    });

    testWidgets('the colour control is in the menu and changes ThemeChoices at once', (tester) async {
      await _pump(tester, _owner(), height: 900);
      await tester.tap(find.byKey(const ValueKey('header-avatar')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('me-theme')), findsOneWidget);
      for (final c in ThemeChoice.values) {
        expect(find.byKey(ValueKey('theme-${c.name}')), findsOneWidget);
      }
      await tester.tap(find.byKey(const ValueKey('theme-dark')));
      await tester.pumpAndSettle();
      expect(themes.current.value, ThemeChoice.dark);
      expect(find.byKey(const ValueKey('avatar-menu')), findsOneWidget, reason: 'রং বদলেও মেনু খোলা');
      await tester.tap(find.byKey(const ValueKey('theme-light')));
      await tester.pumpAndSettle();
      expect(themes.current.value, ThemeChoice.light);
      // ⓘ বাছা ঘর শুধু রঙে নয় — মোটা লেখাতেও (WCAG 1.4.1); কোনো শব্দ মাঝখানে ভাঙে না
      FontWeight? weight(ThemeChoice c) => DefaultTextStyle.of(tester.element(find.byKey(ValueKey('theme-${c.name}')))).style.fontWeight;
      expect(weight(ThemeChoice.light), FontWeight.w700);
      expect(weight(ThemeChoice.dark), isNot(FontWeight.w700));
      for (final c in ThemeChoice.values) {
        final p = tester.renderObject<RenderParagraph>(find.byKey(ValueKey('theme-${c.name}')));
        expect(p.didExceedMaxLines, isFalse, reason: '⛔ "${c.label}" কাটা গেল');
      }
      final seg = tester.getSize(find.byKey(const ValueKey('me-theme')));
      expect(seg.height, greaterThanOrEqualTo(48));
    });

    testWidgets('at 200% text and 320 dp the menu does not break', (tester) async {
      await _pump(tester, _owner(), width: 320, height: 900, scale: 2.0);
      await tester.tap(find.byKey(const ValueKey('header-avatar')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(tester.getRect(find.byKey(const ValueKey('avatar-menu'))).right, lessThanOrEqualTo(320));
    });
  });

  // ⛔ রং আর ভাষার ঘরের লেখা সব ঘরে একই আঁকা মাপে — কোনোটা ছোট করে আঁটানো নয় (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬: FittedBox
  // "অন্ধকার" আর "ফোনের মতো"-কে পাশের ঘরের চেয়ে ছোট আর সরু আঁকত)। আঁকা মাপ = লেখার মাপ × পর্দায় যতটা বড়/ছোট দেখায়
  // ⓘ বাছা ঘরের লেখা মোটা, তাই চওড়া — প্রতিটা রং বাছা অবস্থায়
  for (final width in [320.0, 360.0, 412.0]) {
    for (final picked in ThemeChoice.values) {
    for (final scale in [1.0, 1.3]) {
    testWidgets(
        'avatar menu at ${width.toInt()} dp, ${(scale * 100).round()}% text, ${picked.name} picked: '
        'every colour and language label is drawn at the size of its neighbours', (tester) async {
      await themes.choose(picked);
      await _pump(tester, _owner(), width: width, height: 900, scale: scale);
      await tester.tap(find.byKey(const ValueKey('header-avatar')));
      await tester.pumpAndSettle();
      for (final (row, keys) in [
        ('colour', [for (final c in ThemeChoice.values) 'theme-${c.name}']),
        ('language', [for (final c in LocaleChoice.values) 'language-${c.name}']),
      ]) {
        final drawn = <String, double>{};
        for (final key in keys) {
          final f = find.byKey(ValueKey(key));
          expect(f, findsOneWidget, reason: '$row: $key');
          final p = tester.renderObject<RenderParagraph>(f);
          final shown = tester.getRect(f).width / p.size.width;
          drawn[key] = p.textScaler.scale(p.text.style!.fontSize!) * shown;
          expect(p.didExceedMaxLines, isFalse, reason: '⛔ $row: "$key" কাটা গেল');
          // ⓘ দুই লাইনে ভাঙে কেবল শব্দের ফাঁকে — লাইন শব্দের চেয়ে বেশি মানে কোনো শব্দ মাঝখানে ভেঙেছে ("অন্ধকা / র")
          final words = p.text.toPlainText().trim().split(RegExp(r'\s+')).length;
          final lines = (TextPainter(text: p.text, textDirection: TextDirection.ltr, textScaler: p.textScaler, maxLines: 2)
                ..layout(maxWidth: p.size.width))
              .computeLineMetrics()
              .length;
          expect(lines <= words, isTrue, reason: '⛔ $row: "${p.text.toPlainText()}" শব্দের মাঝখানে ভাঙল');
          // ⓘ লেখা নিজের ঘরের ভেতরে, পাশের ঘরে বা কিনারার বাইরে নয়
          final segment = tester.getRect(find.ancestor(of: f, matching: find.byType(InkWell)).first);
          final label = tester.getRect(f);
          expect(label.left >= segment.left - 0.5 && label.right <= segment.right + 0.5, isTrue,
              reason: '⛔ $row: "$key" $label ঘরের $segment বাইরে');
        }
        final first = drawn.values.first;
        expect(drawn.values.every((s) => (s - first).abs() < 0.01), isTrue, reason: '⛔ $row: ঘরের লেখার আঁকা মাপ আলাদা — $drawn');
      }
    });
    }
    }
  }

  // ⭐ ছবি — বার (মালিক আর বিক্রয়কর্মী, আলো আর অন্ধকার) ৩২০, ৩৬০, ৪১২ dp আর ট্যাবলেট (৮৪০ dp); আর খোলা মেনু
  group('goldens', () {
    for (final dark in [false, true]) {
      final mode = dark ? 'dark' : 'light';
      for (final width in [320.0, 360.0, 412.0, 840.0]) {
        testWidgets('owner $mode ${width.toInt()}', (tester) async {
          await _shot(tester, _owner(), width: width, dark: dark, name: 'top_owner_${width.toInt()}_$mode');
        });
        testWidgets('sr $mode ${width.toInt()}', (tester) async {
          await _shot(tester, _sr(), width: width, dark: dark, name: 'top_sr_${width.toInt()}_$mode');
        });
      }
      testWidgets('avatar menu $mode', (tester) async {
        _goldenVersion = '0.4.61';
        addTearDown(() => _goldenVersion = null);
        // ⓘ আসল ছায়া — টেস্টে ছায়া বন্ধ থাকলে কার্ডের চারপাশে মোটা ধূসর দাগ আঁকা হয়, ফোনে যা নেই
        debugDisableShadows = false;
        await themes.choose(dark ? ThemeChoice.dark : ThemeChoice.light);
        await _pump(tester, _owner(), width: 412, height: 820, dark: dark, statusBar: true, wrap: true);
        await tester.tap(find.byKey(const ValueKey('header-avatar')));
        await tester.pumpAndSettle();
        await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/top_bar/avatar_menu_$mode.png'));
        debugDisableShadows = true;
      });
    }
  });
}

int _lines(RenderParagraph p) => p.getBoxesForSelection(TextSelection(baseOffset: 0, extentOffset: p.text.toPlainText().length))
    .map((b) => b.top.round())
    .toSet()
    .length;

/// মালিক — দুই কোম্পানি, "সব শাখা", ২১টা না-পড়া, হোমে
_BarSpec _owner({bool canSwitchCompany = true, ValueChanged<AvatarChoice?>? onMenu}) => _bar(
      company: 'ADI Wholesale',
      companyCode: 'ADI',
      branch: 'সব শাখা',
      unread: 21,
      onOrgTap: () {},
      menu: (name: 'আল-আমিন শুভ', designation: 'মালিক', canSwitchCompany: canSwitchCompany, onMenu: onMenu),
    );

/// বিক্রয়কর্মী — এক শাখা, বিক্রয় › আদায়, ৩টা না-পড়া, অফলাইন, ২টা পাঠানো বাকি
_BarSpec _sr() => _bar(
      company: 'ADI Wholesale',
      companyCode: 'ADI',
      branch: 'ময়মনসিংহ',
      crumbs: const ['বিক্রয়', 'আদায়'],
      leading: TopBarLeading.back,
      unread: 3,
      offline: true,
      pendingCount: () => 2,
      menu: (name: 'করিম', designation: 'বিক্রয়কর্মী', canSwitchCompany: false, onMenu: null),
    );

/// সোনালি ছবির সংস্করণ — কেবল ছবির পরীক্ষায়; null মানে অ্যাপের নিজের [AppConfig.appVersion]
String? _goldenVersion;

typedef _Menu = ({String name, String? designation, bool canSwitchCompany, ValueChanged<AvatarChoice?>? onMenu});

class _BarSpec {
  _BarSpec(this.build);
  final Widget Function(_MenuState state) build;
}

_BarSpec _bar({
  required String company,
  required String branch,
  String companyCode = '',
  List<String> crumbs = const [],
  TopBarLeading leading = TopBarLeading.menu,
  int? unread,
  bool news = false,
  VoidCallback? onOrgTap,
  bool demo = false,
  bool offline = false,
  SyncStatus? syncStatus,
  int Function()? pendingCount,
  _Menu menu = (name: 'আল-আমিন শুভ', designation: 'মালিক', canSwitchCompany: true, onMenu: null),
}) {
  SyncStatus? status = syncStatus;
  if (offline) {
    status = SyncStatus();
    status.watchNetwork(changes: Stream.value([ConnectivityResult.none]));
  }
  return _BarSpec((state) => Builder(
        builder: (context) => AbosTopBar(
          company: company,
          branch: branch,
          companyCode: companyCode,
          fallbackTitle: menu.name,
          crumbs: crumbs,
          leading: leading,
          onLeading: () {},
          onOrgTap: onOrgTap,
          onSearch: () {},
          onBell: () {},
          unread: unread,
          news: news,
          avatarName: menu.name,
          menuOpen: state.open,
          onAvatar: (top) async {
            state.set(true);
            final choice = await showAvatarMenu(context,
                top: top,
                name: menu.name,
                designation: menu.designation,
                branch: branch,
                canSwitchCompany: menu.canSwitchCompany,
                // ⓘ সোনালি ছবিতে সংস্করণ স্থির ([_goldenVersion]) — নতুন সংস্করণে ছবি না ভাঙে; বাকি পরীক্ষায় অ্যাপের ধ্রুবক
                version: _goldenVersion ?? AppConfig.appVersion);
            state.set(false);
            menu.onMenu?.call(choice);
          },
          demo: demo,
          syncStatus: status ?? (SyncStatus()),
          pendingCount: pendingCount ?? () => 0,
        ),
      ));
}

class _MenuState {
  _MenuState(this.open, this.set);
  final bool open;
  final void Function(bool) set;
}

class _Host extends StatefulWidget {
  const _Host({required this.spec, required this.body});
  final _BarSpec spec;
  final Widget body;
  @override
  State<_Host> createState() => _HostState();
}

class _HostState extends State<_Host> {
  bool _open = false;
  @override
  Widget build(BuildContext context) {
    final bar = widget.spec.build(_MenuState(_open, (v) {
      if (mounted) setState(() => _open = v);
    }));
    return Scaffold(appBar: _Pref(bar), body: widget.body);
  }
}

/// [AbosTopBar] একটা Builder-এর ভেতরে (মেনুর context চাই) — Scaffold-কে তার মাপের ছাদ জানানো
class _Pref extends StatelessWidget implements PreferredSizeWidget {
  const _Pref(this.child);
  final Widget child;
  @override
  Size get preferredSize => const Size.fromHeight(AbosTopBar.maxHeight);
  @override
  Widget build(BuildContext context) => child;
}

Widget _app(_BarSpec spec,
    {required double width,
    required double height,
    double scale = 1,
    bool dark = false,
    bool statusBar = false,
    bool wrap = false,
    Widget? body}) {
  return MediaQuery(
    data: MediaQueryData(
      size: Size(width, height),
      textScaler: TextScaler.linear(scale),
      padding: EdgeInsets.only(top: statusBar ? 24 : 0),
      viewPadding: EdgeInsets.only(top: statusBar ? 24 : 0),
      platformBrightness: dark ? Brightness.dark : Brightness.light,
    ),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: withBarFonts(AppTheme.light()),
      darkTheme: withBarFonts(AppTheme.dark()),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      builder: (context, child) => RepaintBoundary(key: const ValueKey('shot'), child: child),
      home: _Host(spec: spec, body: body ?? (wrap ? const _Body() : const SizedBox.expand())),
    ),
  );
}

/// ছবির পেছনের পাতা — পাতার কাজ অন্যদের; এখানে কেবল পট, যাতে মেনু আর আবছা পর্দা দেখা যায়
class _Body extends StatelessWidget {
  const _Body();
  @override
  Widget build(BuildContext context) => const SizedBox.expand();
}

Future<void> _pump(WidgetTester tester, _BarSpec spec,
    {double width = 412,
    double height = 640,
    double scale = 1,
    bool dark = false,
    bool statusBar = false,
    bool wrap = false,
    Widget? body}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(_app(spec,
      width: width, height: height, scale: scale, dark: dark, statusBar: statusBar, wrap: wrap, body: body));
  await tester.pumpAndSettle();
}

/// বারের ছবি — ফোনের মাথা (২৪dp ঘড়ির জায়গাসহ) আর নিচে একটু পাতা, যাতে ফিতা আর ছায়া দেখা যায়
Future<void> _shot(WidgetTester tester, _BarSpec spec,
    {required double width, required bool dark, required String name}) async {
  await _pump(tester, spec, width: width, height: 170, dark: dark, statusBar: true);
  await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/top_bar/$name.png'));
}
