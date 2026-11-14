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
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

import 'navigation/bar_harness.dart' show rolePermissions, loadRealFonts, realFontTheme;
import 'support/screens_0441.dart' show ownerHomeBody, srCollectBody, sampleTopBar;

/// ⭐ পুরো পর্দার ছবি — মাথার নীল বার, পাতা আর নিচের বার একসাথে, মালিকের অনুমোদিত পুরো-পাতার নমুনার মতো (fe, ২০ অক্টোবর
/// ২০২৬): মালিকের হোম আর SR-এর আদায় × ৪১২dp ফোন আর ট্যাবলেট × আলো আর অন্ধকার। আসল ফন্টে (Roboto, Shonar Bangla,
/// Material Symbols)। ছবি `test/goldens/full_screen/`; মালিকের PDF-এর জন্য `E:\ABOS\a4-bars-shots`-এ নকল হয়।
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

class _Pref extends StatelessWidget implements PreferredSizeWidget {
  const _Pref(this.child);
  final Widget child;
  @override
  Size get preferredSize => const Size.fromHeight(AbosTopBar.maxHeight);
  @override
  Widget build(BuildContext context) => child;
}

const _owner = AuthUser(id: 'u1', name: 'আল-আমিন শুভ', email: 'o@x', roles: ['Owner'], permissions: []);

AuthUser _user(String role) => role == 'owner'
    ? AuthUser(id: 'u1', name: _owner.name, email: _owner.email, roles: _owner.roles, permissions: rolePermissions['owner']!)
    : AuthUser(id: 'u2', name: 'করিম উদ্দিন', email: 'sr@x', roles: const ['SR'], permissions: rolePermissions['sr']!);

GoRouter _router(String initial) => GoRouter(initialLocation: initial, routes: [
      GoRoute(
        path: '/home',
        builder: (context, state) => ScreenFrame(
          location: state.matchedLocation,
          child: Scaffold(
            // ⓘ হোমের বার — HomeShell-এর মতোই: ≡ বাঁয়ে, "কোম্পানি · শাখা", ডানে খোঁজা, ঘণ্টা (ছবি নিচের বারে)
            appBar: _Pref(AbosTopBar(
              company: 'ADI Wholesale',
              companyCode: 'ADI',
              branch: 'সব শাখা',
              fallbackTitle: _owner.name,
              crumbs: const [],
              leading: TopBarLeading.menu,
              onLeading: () {},
              onSearch: () {},
              onBell: () {},
              unread: 3,
              avatarName: _owner.name,
              onAvatar: (_) async {},
              // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): HomeShell-এর মতোই — উপরের বারে ছবি নেই, ছবি নিচের বারের "আমি"
              showAvatar: false,
              syncStatus: SyncStatus(),
              pendingCount: () => 0,
            )),
            body: ownerHomeBody(),
          ),
        ),
        routes: [
          GoRoute(
            path: 'collections',
            builder: (context, state) =>
                ScreenFrame(location: state.matchedLocation, child: srCollectBody(canCollect: true)),
          ),
        ],
      ),
    ]);

Widget _app(String role, {required bool dark}) => ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => _SignedIn(_user(role))),
        topBarContextProvider.overrideWith((ref) => sampleTopBar(moduleOf: (head) => head == 'collections' ? 'বিক্রয়' : null)),
        navBadgesProvider.overrideWith((ref) => const NavBadges(unread: 3, pending: 3)),
        mayCollectProvider.overrideWith((ref) => role == 'sr'),
      ],
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        theme: realFontTheme(AppTheme.light()),
        darkTheme: realFontTheme(AppTheme.dark()),
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
        routerConfig: _router(role == 'owner' ? '/home' : '/home/collections'),
        builder: (context, child) => RepaintBoundary(key: const ValueKey('shot'), child: child!),
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

  // ⓘ শোয়ানো ট্যাবলেট ১২৮০dp — Material 3-এর "expanded": হোমের দুই কার্ড পাশাপাশি
  const sizes = {'412': Size(412, 915), 'tablet': Size(800, 1280), 'tablet_wide': Size(1280, 800)};
  const screens = {'owner_home': 'owner', 'sr_collect': 'sr'};

  for (final MapEntry(key: screen, value: role) in screens.entries) {
    for (final MapEntry(key: size, value: dp) in sizes.entries) {
      for (final dark in [false, true]) {
        final name = '${screen}_${size}_${dark ? 'dark' : 'light'}';
        testWidgets('full screen $name', (tester) async {
          debugDisableShadows = false;
          try {
            tester.view.physicalSize = dp * 2;
            tester.view.devicePixelRatio = 2;
            addTearDown(tester.view.reset);
            await tester.pumpWidget(_app(role, dark: dark));
            await tester.pumpAndSettle();
            if (role == 'sr') {
              // ⓘ নমুনার "আজকের আদায়" — তারিখের বারে "আজ"
              await tester.tap(find.byKey(const ValueKey('range-today')));
              await tester.pumpAndSettle();
              // ⭐ নমুনার পাতা ২: আসল নীল বার, পথ "› বিক্রয় › আদায়", আর "＋ নতুন আদায়"
              expect(find.byKey(const ValueKey('screen-bar')), findsOneWidget);
            }
            await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/full_screen/$name.png'));
            // ⭐ তালিকার একেবারে শেষে — শেষ সারি আর মোট "＋ নতুন" বোতামের ওপরে পুরো দেখা যায় (fe, ২২ অক্টোবর ২০২৬; Material 3:
            // স্ক্রল করা লেখা FAB-এর নিচে আটকে থাকে না)। দাবি fab_never_hides_data_test-এ; এই ছবি চোখে দেখার জন্য।
            for (final s in tester.stateList<ScrollableState>(find.byType(Scrollable))) {
              final p = s.position;
              if (p.axis == Axis.vertical && p.hasContentDimensions) p.jumpTo(p.maxScrollExtent);
            }
            await tester.pumpAndSettle();
            await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/full_screen/${name}_end.png'));
          } finally {
            debugDisableShadows = true;
          }
        });
      }
    }
  }
}
