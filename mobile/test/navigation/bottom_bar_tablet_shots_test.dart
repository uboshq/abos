import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

import '../support/screens_0441.dart';
import 'bar_harness.dart';

/// ⭐ মালিককে দেখানোর ট্যাবলেটের ছবি — খাড়া বার (রেল) আর তার পাশে আসল পাতা, ইংরেজি খসড়া নয় (fe, ২০ অক্টোবর ২০২৬):
/// মালিক, ডিপো আর হিসাবের ছবিতে আসল মালিকের হোম (a4-এর নীল বার "ADI Wholesale · ময়মনসিংহ", নমুনার কার্ড — screens_0441-এর
/// একই নমুনা); SR-এর ছবিতে আসল আদায়ের পাতা (পুরো-পাতার নমুনা, পাতা ২)। bottom_bar_goldens_test-এর ছোট ছবিগুলো আলাদা
/// থাকে (বারের নিজের পরীক্ষা); এগুলো `goldens/shots/` -এ, E:\ABOS\a4-bars-shots\bottom_*_tablet_*.png-এ কপি হয়।
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

/// হোম — HomeShell-এর মতোই: a4-এর বার আর আসল হোমের দেহ
Widget _realHome() => Scaffold(
      appBar: AbosTopBar(
        company: 'ADI Wholesale',
        companyCode: 'ADI',
        branch: 'ময়মনসিংহ',
        fallbackTitle: ownerUser.name,
        demo: false,
        unread: 3,
        avatarName: ownerUser.name,
        onSearch: () {},
        onBell: () {},
        onLeading: () {},
        onAvatar: (_) {},
        // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): HomeShell-এর মতোই — উপরের বারে ছবি নেই, ছবি নিচের বারের "আমি"
        showAvatar: false,
      ),
      body: ownerHomeBody(),
    );

GoRouter _router(String initial) => GoRouter(initialLocation: initial, routes: [
      GoRoute(
        path: '/home',
        builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: _realHome()),
        routes: [
          GoRoute(
            path: 'collections',
            builder: (context, state) =>
                ScreenFrame(location: state.matchedLocation, child: srCollectBody(canReportDeposit: true)),
          ),
        ],
      ),
    ]);

void main() {
  setUpAll(() async {
    await loadRealFonts();
    // ⓘ a6-এর চিপের আইকন — Material Symbols Rounded (a5_screens_0441_goldens_test-এর একই পথ)
    final config = File('.dart_tool/package_config.json').readAsStringSync();
    final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
    final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
    final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
      ..addFont(Future.value(ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
    await loader.load();
  });
  setUp(SyncStatus.instance.reset);

  // ⓘ SR তার নিজের ঘরে (আদায়), বাকিরা হোমে — ডিপো আর হিসাবের নিজের পাতার নমুনা এখনো নেই
  const opened = {
    'owner': '/home',
    'sr': '/home/collections',
    'depot': '/home',
    'accounts': '/home',
  };

  for (final role in rolePermissions.keys) {
    for (final dark in [false, true]) {
      final name = 'bottom_${role}_tablet_${dark ? 'dark' : 'light'}';
      testWidgets('$name shows the real page beside the rail', (tester) async {
        debugDisableShadows = false;
        try {
          await setScreen(tester, const Size(800, 1280), ratio: 2);
          await tester.pumpWidget(ProviderScope(
            overrides: [
              authStateProvider.overrideWith((ref) => _SignedIn(userFor(role))),
              navBadgesProvider.overrideWith((ref) => const NavBadges(pending: 5, unread: 3)),
              mayCollectProvider.overrideWith((ref) => role == 'sr'),
              topBarContextProvider.overrideWith((ref) => sampleTopBar()),
            ],
            child: MaterialApp.router(
              debugShowCheckedModeBanner: false,
              theme: realFontTheme(AppTheme.light()),
              darkTheme: realFontTheme(AppTheme.dark()),
              themeMode: dark ? ThemeMode.dark : ThemeMode.light,
              routerConfig: _router(opened[role]!),
            ),
          ));
          await tester.pumpAndSettle();
          if (role == 'sr') {
            // ⓘ নমুনার "আজকের আদায়"
            await tester.tap(find.byKey(const ValueKey('range-today')));
            await tester.pumpAndSettle();
          }
          // ⓘ আসল পাতা — ইংরেজি খসড়া নয়
          expect(find.textContaining('row 0'), findsNothing);
          expect(find.byType(NavigationRail).evaluate().isNotEmpty || find.byType(RoleRail).evaluate().isNotEmpty, isTrue,
              reason: 'ট্যাবলেটে খাড়া বার');
          if (role == 'sr') {
            expect(find.byWidgetPredicate((w) => w is NumberAndName && w.number == 'RCV-0191' && w.name == 'মুসকান এন্টারপ্রাইজ'), findsOneWidget);
          } else {
            expect(find.byWidgetPredicate((w) => w is NumberAndName && w.number == 'RCV-0190' && w.name == 'রহিম স্টোর'), findsOneWidget);
          }
          await expectLater(find.byType(MaterialApp), matchesGoldenFile('goldens/shots/$name.png'));
        } finally {
          debugDisableShadows = true;
        }
      });
    }
  }
}
