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
/// 2. স্থির অবস্থায় "মোট · 2টি দোকান"-এর সারি "＋ নতুন আদায়" বোতামের নিচে ঢাকা পড়ে না (দুই আয়তক্ষেত্র মেলে না)।
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
                  ScreenFrame(location: state.matchedLocation, child: srCollectBody(canCollect: true)),
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

  testWidgets('412×915 at rest: the "মোট · 2টি দোকান" row is clear of the "+ নতুন আদায়" button', (tester) async {
    await atRest(tester);
    final total = tester.getRect(find.byKey(const ValueKey('shop-dues-total')));
    final fab = tester.getRect(find.byKey(const ValueKey('money-in-new')));
    // ignore: avoid_print
    print('shop-dues-total $total · fab $fab');
    expect(total.overlaps(fab), isFalse,
        reason: '⛔ স্থির অবস্থায় "＋ নতুন আদায়" বোতাম "মোট · 2টি দোকান" ঢেকেছে (মোট $total, বোতাম $fab)');
  });
}
