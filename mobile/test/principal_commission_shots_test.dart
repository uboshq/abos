import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/books/principal_commission_screen.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

import 'navigation/bar_harness.dart' show rolePermissions, loadRealFonts, realFontTheme;
import 'principal_commission_screen_test.dart' show FakePcApi;
import 'support/screens_0441.dart' show sampleTopBar;

/// ⭐ প্রিন্সিপালের কমিশনের পুরো পর্দার ছবি — নীল বার ("› সরবরাহকারী › প্রিন্সিপালের কমিশন"), মাস বাছাই, সারি, নিচের
/// সর্বমোট আর নিচের বার একসাথে, full_screen_shots_test-এর ধাঁচে (মালিকের আদেশ "app e dekhate hobe", fe-র মাধ্যমে; a4-এর
/// নকশার নিয়ম ২; cb, ২০ অক্টোবর ২০২৬): ৪১২dp ফোন আর ট্যাবলেট × আলো আর অন্ধকার, আসল ফন্টে (Roboto, Shonar Bangla,
/// Material Symbols)। ছবি `test/goldens/full_screen/principal_commission_*.png`।
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

final _owner = AuthUser(
    id: 'u1', name: 'আল-আমিন শুভ', email: 'o@x', roles: const ['Owner'], permissions: rolePermissions['owner']!);

GoRouter _router() => GoRouter(initialLocation: '/home/principal-commission', routes: [
      GoRoute(
        path: '/home',
        builder: (context, state) => const SizedBox(),
        routes: [
          GoRoute(
            path: 'principal-commission',
            builder: (context, state) => ScreenFrame(
              location: state.matchedLocation,
              child: PrincipalCommissionScreen(api: FakePcApi(), clock: () => DateTime(2026, 10, 20)),
            ),
          ),
        ],
      ),
    ]);

Widget _app({required bool dark}) => ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => _SignedIn(_owner)),
        topBarContextProvider.overrideWith((ref) => sampleTopBar()),
        navBadgesProvider.overrideWith((ref) => const NavBadges(unread: 3, pending: 3)),
      ],
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        theme: realFontTheme(AppTheme.light()),
        darkTheme: realFontTheme(AppTheme.dark()),
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
        routerConfig: _router(),
        builder: (context, child) => RepaintBoundary(key: const ValueKey('shot'), child: child!),
      ),
    );

Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  for (final family in ['Rounded', 'Outlined']) {
    final file = File('$root/lib/fonts/MaterialSymbols$family.ttf');
    if (!file.existsSync()) continue;
    final loader = FontLoader('packages/material_symbols_icons/MaterialSymbols$family')
      ..addFont(Future.value(ByteData.view(Uint8List.fromList(file.readAsBytesSync()).buffer)));
    await loader.load();
  }
}

void main() {
  setUpAll(() async {
    await loadRealFonts();
    await _loadRounded();
  });

  const sizes = {'412': Size(412, 915), 'tablet': Size(800, 1280)};
  for (final MapEntry(key: size, value: dp) in sizes.entries) {
    for (final dark in [false, true]) {
      final name = 'principal_commission_${size}_${dark ? 'dark' : 'light'}';
      testWidgets('full screen $name', (tester) async {
        debugDisableShadows = false;
        try {
          tester.view.physicalSize = dp * 2;
          tester.view.devicePixelRatio = 2;
          addTearDown(tester.view.reset);
          await tester.pumpWidget(_app(dark: dark));
          await tester.pumpAndSettle();
          expect(find.byKey(const ValueKey('screen-bar')), findsOneWidget);
          expect(tester.widget<Text>(find.byKey(const ValueKey('top-crumbs'))).data, '› সরবরাহকারী › প্রিন্সিপালের কমিশন');
          expect(find.byKey(const ValueKey('pc-totals-bar')), findsOneWidget);
          expect(tester.takeException(), isNull);
          await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/full_screen/$name.png'));
        } finally {
          debugDisableShadows = true;
        }
      });
    }
  }
}
