import 'dart:io';

import 'package:flutter/material.dart';
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
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

import 'navigation/bar_harness.dart' show rolePermissions, loadRealFonts, realFontTheme;
import 'support/screens_0441.dart' show srCollectBody, sampleTopBar;

/// ⭐ মোটের সময়কাল পট্টির উচ্চতা বাড়ায় না — ঢাকা মোট নয় (fe-র এক নম্বর অগ্রাধিকার; সমন্বয়ক, ২৪ অক্টোবর ২০২৬; ০.৪.৪৮)।
///
/// <p>আসল পর্দা: SR-এর আদায়, ৪১২×৯১৫ ফোন, ১০০% লেখা, আসল ফন্ট, "আজ" বাছা — full_screen_shots-এর একই নমুনা, স্ক্রল ছাড়া।
/// দাবি:
/// 1. নিচের মোটের পট্টি ede7957d0-এর চেয়ে উঁচু নয় — সেখানে মাপা উচ্চতা [_heightAtEde7957d0]।
/// 2. তালিকা "＋ নতুন আদায়"-এর বারের ওপরে শেষ হয়; "মোট · 2টি দোকান"-এ স্ক্রল করলে সারিটা পুরো দেখা যায়, বোতামের নিচে নয়
///    (১০ নভেম্বর ২০২৬ পর্যন্ত: স্থির অবস্থাতেই — সারিতে "অ্যাকশন ▾" আসার আগে)।
///
/// ⓘ ede7957d0-এ এই ফাইলটাই চালিয়ে মাপা: পট্টি [_heightAtEde7957d0] dp।
const _heightAtEde7957d0 = 74.0;

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

final _sr = AuthUser(id: 'u2', name: 'করিম উদ্দিন', email: 'sr@x', roles: const ['SR'], permissions: rolePermissions['sr']!);

Widget _app() => ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => _SignedIn(_sr)),
        topBarContextProvider.overrideWith((ref) => sampleTopBar(moduleOf: (head) => head == 'collections' ? 'বিক্রয়' : null)),
        navBadgesProvider.overrideWith((ref) => const NavBadges(unread: 3, pending: 3)),
        mayCollectProvider.overrideWith((ref) => true),
      ],
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        theme: realFontTheme(AppTheme.light()),
        routerConfig: GoRouter(initialLocation: '/home/collections', routes: [
          GoRoute(path: '/home', builder: (_, __) => const SizedBox(), routes: [
            GoRoute(
              path: 'collections',
              builder: (context, state) =>
                  ScreenFrame(location: state.matchedLocation, child: srCollectBody(canReportDeposit: true)),
            ),
          ]),
        ]),
      ),
    );

Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
    ..addFont(Future.value(ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
  await loader.load();
}

void main() {
  setUpAll(() async {
    await loadRealFonts();
    await _loadRounded();
  });

  Future<void> atRest(WidgetTester tester) async {
    tester.view.physicalSize = const Size(412, 915) * 2;
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(_app());
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('range-today')));
    await tester.pumpAndSettle();
  }

  // ⓘ দুই দাবি দুই পরীক্ষায় — একটার ব্যর্থতা অন্যটাকে লুকায় না
  testWidgets('412×915 at 100% text: the totals bar is no taller than at ede7957d0', (tester) async {
    await atRest(tester);
    final bar = tester.getSize(find.byKey(const ValueKey('totals-bar'))).height;
    // ignore: avoid_print
    print('totals-bar height $bar');
    expect(bar, lessThanOrEqualTo(_heightAtEde7957d0 + 0.01),
        reason: '⛔ মোটের পট্টি ede7957d0-এর চেয়ে উঁচু ($bar > $_heightAtEde7957d0) — সময়কাল নিজের লাইন নিয়েছে');
  });

  // ⓘ ১০ নভেম্বর ২০২৬ (০.৪.৫৩): আদায়ের সারিতে "অ্যাকশন ▾" (নমুনা v4) সারি উঁচু করল — ৪১২dp-তেও এখন "মোট · 2টি দোকান" স্থির অবস্থায়
  //   ভাঁজের নিচে, ৩৬০dp-তে যেমন আগে থেকেই। দাবিটা তাই মালিকের আসল নিয়মে ("＋ নতুন" কখনো তথ্য ঢাকে না, 52b52597a): তালিকা বারের
  //   ওপরে শেষ হয় — দেখা কোনো সারি বোতামের নিচে নেই; আর মোটের সারিতে স্ক্রল করলে সেটা পুরো দেখা যায়, বোতাম থেকে আলাদা।
  testWidgets('412×915: the list ends above the "+ নতুন আদায়" bar, and "মোট · 2টি দোকান" scrolled to is clear of it', (tester) async {
    await atRest(tester);
    final fab = tester.getRect(find.byKey(const ValueKey('money-in-deposit-report')));
    final bar = tester.getRect(find.byKey(const ValueKey('totals-bar')));
    // ⓘ মোটের সারি যে স্ক্রলের ভিতরে — পাতার তালিকা (তারিখের চিপের আড়াআড়ি স্ক্রল নয়)
    final list = tester.getRect(find.ancestor(of: find.byKey(const ValueKey('shop-dues-total')), matching: find.byType(Scrollable)).first);
    // ignore: avoid_print
    print('list $list · bar $bar · fab $fab');
    expect(list.bottom, lessThanOrEqualTo(fab.top + 0.01),
        reason: '⛔ তালিকা "＋ নতুন আদায়"-এর নিচে পর্যন্ত চলে — বোতাম কোনো সারি ঢাকতে পারে (তালিকা $list, বোতাম $fab)');
    expect(list.bottom, lessThanOrEqualTo(bar.top + 0.01), reason: '⛔ তালিকা মোটের পট্টির নিচে চলে (তালিকা $list, পট্টি $bar)');

    final dues = find.byKey(const ValueKey('shop-dues-total'));
    await tester.ensureVisible(dues);
    await tester.pumpAndSettle();
    final total = tester.getRect(dues);
    // ignore: avoid_print
    print('shop-dues-total scrolled $total');
    expect(total.overlaps(fab), isFalse, reason: '⛔ স্ক্রল করেও "＋ নতুন আদায়" বোতাম "মোট · 2টি দোকান" ঢাকে (মোট $total, বোতাম $fab)');
    expect(total.bottom, lessThanOrEqualTo(list.bottom + 0.01), reason: '⛔ স্ক্রল করেও মোটের সারি পুরো দেখা যায় না (মোট $total, তালিকা $list)');
  });
}
