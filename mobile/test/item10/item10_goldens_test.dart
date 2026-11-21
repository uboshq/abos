import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/profile/me_profile_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/home/quick_actions.dart';
import 'package:abos_mobile/features/menu/menu_page.dart';
import 'package:abos_mobile/features/profile/me_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../fakes/fake_me_profile_api.dart';
import '../profile/profile_fakes.dart';
import '../support/bar_fonts.dart';

/// ⭐ আইটেম ১০-এর ছবি — মালিককে দেখানোর জন্য (নমুনা TjvNzZvq "ক": "শুভ আহমেদ · মালিক · ADI Corporation · TD শাখা")।
///
/// <p>"আমি" ([MePage]) আর ≡ মেনু ([MenuPage]), তিন রূপে (স্মার্ট, গ্লাস, ক্লাসিক), ৪১২×৯০০ — ফোনে প্রথম পর্দায় যা দেখা যায়; আর প্রতিটার
/// পুরো পাতা (লম্বা ছবি, সব অংশ একসাথে); আর ক্লাসিকের অন্ধকার। ⓘ ঘড়ি আর সংস্করণ স্থির — সোনালি ছবি দিন বা সংস্করণ বদলালে
/// ভাঙে না। ছবিগুলো `test/item10/goldens/`-এ।
void main() {
  setUpAll(loadBarFonts);

  late AppLooks saved;
  setUp(() {
    saved = AppLooks.instance;
    final box = <String, String>{};
    AppLooks.instance = AppLooks(read: (k) async => box[k], write: (k, v) async => box[k] = v);
  });
  tearDown(() => AppLooks.instance = saved);

  const features = MeFeatures(profilePhoto: true, myPhones: true, profilePage: true, coverPhoto: true);
  DateTime clock() => DateTime(2026, 11, 20, 12);

  ThemeData themeOf(AppLook look, {bool dark = false}) => withBarFonts(switch (look) {
        AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
        AppLook.expressive => AppTheme.expressive(),
        AppLook.abos => dark ? AppTheme.dark() : AppTheme.light(),
      });

  Future<void> shoot(WidgetTester tester, AppLook look, Widget page, String name, {bool dark = false, double height = 900}) async {
    tester.view.physicalSize = Size(412, height);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    AppLooks.instance.look.value = look;

    final c = ProviderContainer(overrides: [
      authStateProvider.overrideWith((ref) => SignedIn()),
      meProfileApiProvider.overrideWithValue(FakeMeProfileApi()),
    ]);
    addTearDown(c.dispose);
    c.read(meFeaturesProvider.notifier).state = features;
    c.read(myFaceProvider.notifier).state = null;

    await tester.pumpWidget(UncontrolledProviderScope(
      container: c,
      child: RepaintBoundary(
        key: const ValueKey('shot'),
        child: MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: themeOf(look, dark: dark),
          locale: AppLocale.locale,
          supportedLocales: AppLocale.supported,
          localizationsDelegates: AppLocale.delegates,
          // ⓘ কাচের পট looks_goldens_test-এর মতো `home`-এ — পুরো অ্যাপের মোড়কে রাখলে তার ঢেউ থামে না
          home: look == AppLook.glass
              ? GlassBackdrop(child: Scaffold(backgroundColor: Colors.transparent, body: page))
              : Scaffold(body: page),
        ),
      ),
    ));
    if (look == AppLook.glass) {
      // ⓘ কাচের পটের ঢেউ চলতেই থাকে — স্থির সময় অপেক্ষা (পাতার তথ্য আসা আর ছবি আঁকা শেষ হয়)
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
    } else {
      await tester.pumpAndSettle();
    }
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/$name.png'));
  }

  MePage me() => MePage(
        fallback: MeProfile.fallback(
          name: 'শুভ আহমেদ',
          designation: 'মালিক',
          company: 'ADI Corporation',
          branch: 'TD শাখা',
          email: 'shuvo@example.com',
        ),
        profileApi: FakeProfileApi(pages: [
          [
            phone('aaaa1111', name: 'Samsung Galaxy S23', current: true, used: DateTime(2026, 11, 20, 11, 50)),
            phone('bbbb2222', name: 'Xiaomi Redmi Note 12', used: DateTime(2026, 11, 17, 9)),
          ],
        ]),
        now: clock,
        onEditProfile: () {},
        onSeeAllRecent: () {},
        onLockDelay: () {},
      );

  // ⓘ শর্টকাট — অ্যাপের আসল দ্রুত কাজের ক্রমে (home_shell `_quickActions`), নাম নমুনার মতো বাংলায়
  final shortcuts = <QuickAction>[
    const QuickAction(icon: Symbols.payments, path: 'deposit-report', label: 'টাকা জমার খবর'),
    const QuickAction(icon: Symbols.add_shopping_cart, path: 'new-order', label: 'নতুন অর্ডার'),
    const QuickAction(icon: Symbols.fact_check, path: 'approvals', label: 'অনুমোদন'),
    const QuickAction(icon: Symbols.insights, path: 'today', label: 'আজকের হিসাব'),
    const QuickAction(icon: Symbols.storefront, path: 'customers', label: 'দোকান খুঁজুন'),
    const QuickAction(icon: Symbols.shopping_bag, path: 'vouchers/expense', label: 'নতুন খরচ'),
    const QuickAction(icon: Symbols.receipt_long, path: 'vouchers', label: 'নতুন ভাউচার'),
    const QuickAction(icon: Symbols.request_quote, path: 'claims', label: 'খরচের দাবি'),
  ];

  MenuPage menu() => MenuPage(
        name: 'শুভ আহমেদ',
        designation: 'মালিক',
        company: 'ADI Corporation',
        branch: 'TD শাখা',
        allBranches: false,
        onOpenProfile: () {},
        onSwitchWorkspace: () {},
        shortcuts: shortcuts,
        shortcutCounts: const {'approvals': 3},
        onOpenPath: (_, __) async {},
        recents: const [
          RecentEntry(path: 'paper/money-in/142', label: 'RCV-0142 · টাকা প্রাপ্তি'),
          RecentEntry(path: 'day-close', label: 'দিন বন্ধের হিসাব'),
          RecentEntry(path: 'orders', label: 'বিক্রয় তালিকা'),
        ],
        approvalsWaiting: 3,
        unreadNotices: 20,
        onApprovals: () {},
        onNotices: () {},
        onLookAndColour: () {},
        onLanguage: () {},
        onFingerprint: () {},
        onPrinter: () {},
        onSyncStatus: () {},
        onCheckUpdate: () {},
        onLogout: () {},
        version: '0.4.63',
      );

  for (final look in [AppLook.expressive, AppLook.glass, AppLook.abos]) {
    testWidgets('আমি — ${look.name}, first screen', (tester) async {
      await shoot(tester, look, me(), 'me_${look.name}');
    });
    testWidgets('আমি — ${look.name}, whole page', (tester) async {
      await shoot(tester, look, me(), 'me_${look.name}_full', height: 2300);
    });
    testWidgets('≡ menu — ${look.name}, first screen', (tester) async {
      await shoot(tester, look, menu(), 'menu_${look.name}');
    });
    testWidgets('≡ menu — ${look.name}, whole page', (tester) async {
      await shoot(tester, look, menu(), 'menu_${look.name}_full', height: 2000);
    });
  }

  testWidgets('আমি — classic dark', (tester) async {
    await shoot(tester, AppLook.abos, me(), 'me_abos_dark', dark: true);
  });
  testWidgets('≡ menu — classic dark', (tester) async {
    await shoot(tester, AppLook.abos, menu(), 'menu_abos_dark', dark: true);
  });
}
